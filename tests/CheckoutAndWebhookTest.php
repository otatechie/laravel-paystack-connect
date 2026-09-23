<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Events\PaymentAmountMismatch;
use Otatechie\PaystackConnect\Events\PaymentFailed;
use Otatechie\PaystackConnect\Events\PaymentSucceeded;
use Otatechie\PaystackConnect\Events\WebhookHandled;
use Otatechie\PaystackConnect\Events\WebhookReceived;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Models\WebhookEvent;
use Otatechie\PaystackConnect\Support\Money;
use Otatechie\PaystackConnect\Tests\Fixtures\Business;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/abc123',
            'access_code' => 'abc123',
        ]]),
    ]);

    $this->business = Business::create(['name' => 'Kofi Prints']);

    Subaccount::create([
        'owner_type' => Business::class,
        'owner_id' => $this->business->id,
        'subaccount_code' => 'ACCT_kofi',
        'business_name' => 'Kofi Prints',
        'settlement_bank' => 'MTN',
        'account_type' => 'mobile_money',
        'account_number' => '0241234567',
        'account_number_last4' => '4567',
        'currency' => 'GHS',
    ]);
});

function chargeSuccess(Payment $payment, array $overrides = []): string
{
    return json_encode([
        'event' => 'charge.success',
        'data' => array_merge([
            'id' => 4099260516,
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'channel' => 'mobile_money',
            'fees' => 195,
            'paid_at' => '2026-09-23T10:00:00.000Z',
            // Slashes are why re-encoding with json_encode breaks signatures.
            'authorization' => ['receipt_url' => 'https://paystack.com/receipt/r/abc'],
        ], $overrides),
    ], JSON_UNESCAPED_SLASHES);
}

it('starts a split payment with the fee, subaccount and exact amount', function () {
    $payment = PaystackConnect::checkout()
        ->amount('19.99', 'GHS')
        ->email('client@example.com')
        ->seller($this->business)
        ->for($this->business)
        ->callbackUrl('https://app.test/paid')
        ->create();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(1999)
        ->and($payment->platform_fee)->toBe(500) // the GHS 5 minimum
        ->and($payment->sellerShare()->toMajorString())->toBe('14.99')
        ->and($payment->authorization_url)->toBe('https://checkout.paystack.com/abc123');

    Http::assertSent(fn (Request $request) => $request['amount'] === 1999
        && $request['subaccount'] === 'ACCT_kofi'
        && $request['transaction_charge'] === 500
        && $request['bearer'] === 'account'
        && $request['currency'] === 'GHS'
        && json_decode($request['metadata'], true)['paystack_connect_payment_id'] === $payment->id);
});

it('refuses to check out to a seller without a subaccount', function () {
    PaystackConnect::checkout()->seller(Business::create(['name' => 'No Account Ltd']));
})->throws(InvalidArgumentException::class, 'no active Paystack subaccount');

it('marks the payment paid from a genuine webhook, even with slashes in the body', function () {
    Event::fake([PaymentSucceeded::class, WebhookReceived::class]);
    $payment = PaystackConnect::checkout()->amount('19.99')->email('c@example.com')->seller($this->business)->create();

    $body = chargeSuccess($payment);
    expect(json_encode(json_decode($body, true)))->not->toBe($body); // re-encoding changes the bytes

    $this->postWebhook($body)->assertOk()->assertJson(['status' => 'ok']);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Success)
        ->and($payment->paystack_fee)->toBe(195)
        ->and($payment->channel)->toBe('mobile_money')
        ->and($payment->paid_at)->not->toBeNull();

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    Event::assertDispatched(WebhookReceived::class, fn ($e) => $e->event === 'charge.success');
});

it('rejects webhooks with a missing or wrong signature', function () {
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    $this->postWebhook(chargeSuccess($payment), 'forged')->assertUnauthorized();
    $this->postWebhook(chargeSuccess($payment), '')->assertUnauthorized();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(WebhookEvent::count())->toBe(0);
});

it('processes a retried webhook only once', function () {
    Event::fake([PaymentSucceeded::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->seller($this->business)->create();
    $body = chargeSuccess($payment);

    $this->postWebhook($body)->assertJson(['status' => 'ok']);
    $this->postWebhook($body)->assertJson(['status' => 'duplicate']);
    $this->postWebhook($body)->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    expect(WebhookEvent::count())->toBe(1);
});

it('does not mark a payment paid when the amount differs', function () {
    Event::fake([PaymentSucceeded::class, PaymentAmountMismatch::class]);
    $payment = PaystackConnect::checkout()->amount('250.00')->email('c@example.com')->create();

    $this->postWebhook(chargeSuccess($payment, ['amount' => 100]))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::AmountMismatch);
    Event::assertNotDispatched(PaymentSucceeded::class);
    Event::assertDispatched(PaymentAmountMismatch::class);
});

it('does not mark a payment paid when the currency differs', function () {
    $payment = PaystackConnect::checkout()->amount('250.00', 'GHS')->email('c@example.com')->create();

    $this->postWebhook(chargeSuccess($payment, ['currency' => 'NGN']))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::AmountMismatch);
});

it('retries processing when a listener failed the first time', function () {
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();
    $body = chargeSuccess($payment);

    $fail = true;
    Event::listen(WebhookReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Mail server down');
        }
    });

    $this->postWebhook($body)->assertStatus(500);
    expect(WebhookEvent::first()->processed_at)->toBeNull()
        ->and(WebhookEvent::first()->error)->toBe('Mail server down');

    $fail = false;
    $this->postWebhook($body)->assertJson(['status' => 'ok']);
    expect(WebhookEvent::first()->processed_at)->not->toBeNull();
});

it('turns away an overlapping delivery while the first is still being processed', function () {
    Event::fake([PaymentSucceeded::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();
    $body = chargeSuccess($payment);

    // Another request is processing this exact payload right now.
    WebhookEvent::create(['event' => 'charge.success', 'payload_hash' => hash('sha256', $body), 'payload' => json_decode($body, true), 'claimed_at' => now()]);

    $this->postWebhook($body)->assertJson(['status' => 'processing']);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentSucceeded::class);

    // A claim older than a minute belongs to a request that died; take it over.
    WebhookEvent::query()->update(['claimed_at' => now()->subMinutes(2)]);

    $this->postWebhook($body)->assertJson(['status' => 'ok']);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Success);
});

it('acknowledges charges it did not create without failing', function () {
    $this->postWebhook(json_encode(['event' => 'charge.success', 'data' => [
        'reference' => 'made-elsewhere', 'status' => 'success', 'amount' => 100, 'currency' => 'GHS',
    ]]))->assertOk();
});

it('verifies a payment on the callback page and agrees with the webhook', function () {
    Event::fake([PaymentSucceeded::class]);
    $payment = PaystackConnect::checkout()->amount('75.50')->email('c@example.com')->create();

    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => json_decode(chargeSuccess($payment), true)['data']]),
    ]);

    expect(PaystackConnect::verify($payment->reference)->status)->toBe(PaymentStatus::Success);

    // The webhook arriving afterwards changes nothing.
    $this->postWebhook(chargeSuccess($payment))->assertOk();
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('keeps a payment pending when Paystack reports it abandoned', function () {
    Event::fake([PaymentFailed::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    // Paystack says "abandoned" for any checkout the customer hasn't paid yet.
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'reference' => $payment->reference, 'status' => 'abandoned', 'amount' => 5000, 'currency' => 'GHS',
        ]]),
    ]);

    expect(PaystackConnect::verify($payment->reference)->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentFailed::class);
});

it('marks a payment paid when the customer succeeds after a declined attempt', function () {
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'reference' => $payment->reference, 'status' => 'failed', 'amount' => 5000, 'currency' => 'GHS',
        ]]),
    ]);
    expect(PaystackConnect::verify($payment->reference)->status)->toBe(PaymentStatus::Failed);

    // Asking again reports the same outcome only once.
    PaystackConnect::verify($payment->reference);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);

    // The customer tries another card on the same checkout.
    $this->postWebhook(chargeSuccess($payment))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Success);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('applies a fee set before the amount in the amount\'s currency', function () {
    $lagos = Business::create(['name' => 'Ade Stores']);
    Subaccount::create([
        'owner_type' => Business::class, 'owner_id' => $lagos->id, 'subaccount_code' => 'ACCT_ade', 'business_name' => 'Ade Stores',
        'settlement_bank' => '058', 'account_number' => '0123456789', 'account_number_last4' => '6789', 'currency' => 'NGN',
    ]);

    $payment = PaystackConnect::checkout()
        ->fee('10.00')
        ->amount('100.00', 'NGN')
        ->email('c@example.com')
        ->seller($lagos)
        ->create();

    expect($payment->platformFee()->equals(Money::major('10.00', 'NGN')))->toBeTrue();
});

it('refuses to send a seller money in a currency their account does not use', function () {
    PaystackConnect::checkout()->amount('100.00', 'NGN')->email('c@example.com')->seller($this->business)->create();
})->throws(InvalidArgumentException::class, 'GHS');

it('sends the optional checkout details to Paystack', function () {
    $payment = PaystackConnect::checkout()
        ->amount('50')->email('c@example.com')->seller($this->business)
        ->reference('INV-42')
        ->bearer('subaccount')
        ->channels(['mobile_money'])
        ->metadata(['order_id' => 7])
        ->create();

    expect($payment->reference)->toBe('INV-42')
        ->and($payment->metadata)->toBe(['order_id' => 7]);

    Http::assertSent(fn (Request $request) => $request['reference'] === 'INV-42'
        && $request['bearer'] === 'subaccount'
        && $request['channels'] === ['mobile_money']
        && json_decode($request['metadata'], true)['order_id'] === 7);
});

it('refuses a reference Paystack would reject', function () {
    PaystackConnect::checkout()->reference('INV/42');
})->throws(InvalidArgumentException::class, 'INV/42');

it('explains a duplicate reference instead of failing on the database', function () {
    PaystackConnect::checkout()->amount('50')->email('c@example.com')->reference('INV-42')->create();
    PaystackConnect::checkout()->amount('50')->email('c@example.com')->reference('INV-42')->create();
})->throws(InvalidArgumentException::class, 'INV-42');

it('keeps checkout secrets and raw Paystack data out of JSON', function () {
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();
    $this->postWebhook(chargeSuccess($payment))->assertOk();

    $json = json_encode($payment->refresh());

    expect($json)->not->toContain('"access_code"')
        ->and($json)->not->toContain('"paystack_data"')
        ->and($json)->not->toContain('receipt_url')
        ->and($json)->toContain('"status":"success"');
});

it('marks a payment paid when Paystack added its fee on top for the customer', function () {
    Event::fake([PaymentSucceeded::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    // With "charge customer the fee" on, amount is what was taken; requested_amount is what we asked for.
    $this->postWebhook(chargeSuccess($payment, ['amount' => 5098, 'requested_amount' => 5000]))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Success);
    Event::assertDispatched(PaymentSucceeded::class);
});

it('does not decide a payment from a payload with no amount', function () {
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();
    $data = json_decode(chargeSuccess($payment), true);
    unset($data['data']['amount']);

    $this->postWebhook(json_encode($data))->assertStatus(500);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and(WebhookEvent::first()->error)->toContain('amount');
});

it('only accepts webhooks from the allowed IPs when a list is set', function () {
    config()->set('paystack-connect.webhook.allowed_ips', ['52.31.139.75']);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    $this->postWebhook(chargeSuccess($payment))->assertForbidden(); // the test client comes from 127.0.0.1
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('turns a non-JSON reply from Paystack into a PaystackException', function () {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response('<html>Bad gateway</html>', 200)]);

    PaystackConnect::verify('anything');
})->throws(PaystackException::class);

it('announces a webhook after it has been handled, so listeners see the saved payment', function () {
    $seen = null;
    Event::listen(WebhookHandled::class, function (WebhookHandled $event) use (&$seen) {
        $seen = Payment::where('reference', $event->payload['data']['reference'])->first()->status;
    });
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    $this->postWebhook(chargeSuccess($payment))->assertOk();
    $this->postWebhook(chargeSuccess($payment))->assertJson(['status' => 'duplicate']);

    expect($seen)->toBe(PaymentStatus::Success);
});

it('retries failed webhooks from the database with a command', function () {
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    $fail = true;
    Event::listen(WebhookReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Mail server down');
        }
    });

    $this->postWebhook(chargeSuccess($payment))->assertStatus(500);
    $fail = false;

    $this->artisan('paystack-connect:retry-webhooks')
        ->expectsOutputToContain('1 retried, 0 still failing')
        ->assertSuccessful();

    expect(WebhookEvent::first()->processed_at)->not->toBeNull()
        ->and(WebhookEvent::first()->error)->toBeNull();

    // Nothing left to retry.
    $this->artisan('paystack-connect:retry-webhooks')->expectsOutputToContain('0 retried');
});

it('prunes processed webhook events after the configured number of days', function () {
    config()->set('paystack-connect.webhook.keep_days', 30);
    $make = fn (string $hash, ?string $processed, int $days) => WebhookEvent::create([
        'event' => 'charge.success', 'payload_hash' => $hash, 'payload' => [], 'processed_at' => $processed,
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);
    $make('old-done', now()->subDays(40)->toDateTimeString(), 40);
    $make('recent-done', now()->subDay()->toDateTimeString(), 1);
    $make('old-failed', null, 40); // never processed: kept so it can be retried

    $this->artisan('model:prune', ['--model' => WebhookEvent::class])->assertSuccessful();

    expect(WebhookEvent::pluck('payload_hash')->sort()->values()->all())->toBe(['old-failed', 'recent-done']);
});

it('lets the thing being paid for find its payments', function () {
    $invoice = Business::create(['name' => 'Invoice 42']); // any model using HasPaystackPayments
    $other = Business::create(['name' => 'Invoice 43']);

    expect($invoice->isPaidOnPaystack())->toBeFalse()
        ->and($invoice->latestPaystackPayment())->toBeNull();

    $first = PaystackConnect::checkout()->amount('50')->email('c@example.com')->for($invoice)->create();
    $second = PaystackConnect::checkout()->amount('50')->email('c@example.com')->for($invoice)->create();
    PaystackConnect::checkout()->amount('50')->email('c@example.com')->for($other)->create();

    expect($invoice->paystackPayments()->pluck('id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($invoice->latestPaystackPayment()->is($second))->toBeTrue()
        ->and($invoice->isPaidOnPaystack())->toBeFalse();

    $this->postWebhook(chargeSuccess($first))->assertOk();

    expect($invoice->isPaidOnPaystack())->toBeTrue()
        ->and($other->isPaidOnPaystack())->toBeFalse();
});
