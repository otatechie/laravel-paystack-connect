<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Events\PaymentAmountMismatch;
use Otatechie\PaystackConnect\Events\PaymentFailed;
use Otatechie\PaystackConnect\Events\PaymentSucceeded;
use Otatechie\PaystackConnect\Events\WebhookReceived;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Models\WebhookEvent;
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

it('marks a payment paid when the customer succeeds after an abandoned or failed attempt', function (string $earlier, PaymentStatus $recorded) {
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    // The customer came back early, or their first card was declined.
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'reference' => $payment->reference, 'status' => $earlier, 'amount' => 5000, 'currency' => 'GHS',
        ]]),
    ]);
    expect(PaystackConnect::verify($payment->reference)->status)->toBe($recorded);

    // Asking again reports the same outcome only once.
    PaystackConnect::verify($payment->reference);
    Event::assertDispatchedTimes(PaymentFailed::class, 1);

    $this->postWebhook(chargeSuccess($payment))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Success);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
})->with([
    'abandoned' => ['abandoned', PaymentStatus::Abandoned],
    'failed' => ['failed', PaymentStatus::Failed],
]);
