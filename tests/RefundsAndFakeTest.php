<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Events\PaymentRefunded;
use Otatechie\PaystackConnect\Events\PaymentSucceeded;
use Otatechie\PaystackConnect\Events\WebhookReceived;
use Otatechie\PaystackConnect\Exceptions\InvalidAmount;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Support\Money;
use Otatechie\PaystackConnect\Support\SettlementAccount;
use Otatechie\PaystackConnect\Tests\Fixtures\Business;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(fn () => Http::preventStrayRequests());

function paidPayment(string $amount = '100.00'): Payment
{
    $fake = PaystackConnect::fake();

    return $fake->pay(PaystackConnect::checkout()->amount($amount, 'GHS')->email('c@example.com')->create());
}

function refundWebhook(Payment $payment, int $amount, string $status = 'processed'): string
{
    return json_encode(['event' => "refund.{$status}", 'data' => [
        'id' => random_int(1, 999999),
        'status' => $status,
        'transaction_reference' => $payment->reference,
        'amount' => $amount,
        'currency' => $payment->currency,
    ]]);
}

it('asks Paystack for a full refund by default', function () {
    $payment = paidPayment();
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending']])]);
    app()->forgetInstance(PaystackClient::class);

    PaystackConnect::refund($payment);

    Http::assertSent(fn ($request) => $request['transaction'] === $payment->reference && $request['amount'] === 10000);
});

it('refuses refunds that are too large, in the wrong currency, or on unpaid payments', function () {
    $payment = paidPayment();

    expect(fn () => PaystackConnect::refund($payment, Money::major('100.01', 'GHS')))->toThrow(InvalidAmount::class)
        ->and(fn () => PaystackConnect::refund($payment, Money::major('10', 'NGN')))->toThrow(InvalidAmount::class)
        ->and(fn () => PaystackConnect::refund(PaystackConnect::checkout()->amount('5')->email('c@example.com')->create()))
        ->toThrow(InvalidArgumentException::class, 'Only a successful payment');
});

it('records partial and full refunds from the webhook', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = paidPayment();

    $this->postWebhook(refundWebhook($payment, 4000))->assertOk();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Success)
        ->and($payment->refundedAmount()->toMajorString())->toBe('40.00')
        ->and($payment->refundableAmount()->toMajorString())->toBe('60.00');

    // A second refund for more than what's left is capped at what was paid.
    $this->postWebhook(refundWebhook($payment, 9000))->assertOk();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_amount)->toBe(10000)
        ->and($payment->refunded_at)->not->toBeNull();

    Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    Event::assertDispatched(PaymentRefunded::class, fn ($e) => $e->amount->minor === 6000);
});

it('counts a retried refund webhook once', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = paidPayment();
    $body = refundWebhook($payment, 2500);

    $this->postWebhook($body)->assertJson(['status' => 'ok']);
    $this->postWebhook($body)->assertJson(['status' => 'duplicate']);

    expect($payment->refresh()->refunded_amount)->toBe(2500);
    Event::assertDispatchedTimes(PaymentRefunded::class, 1);
});

it('counts a refund once when Paystack retries after a listener failed', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = paidPayment();
    $body = refundWebhook($payment, 2500);

    $fail = true;
    Event::listen(WebhookReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Mail server down');
        }
    });

    $this->postWebhook($body)->assertStatus(500);
    $fail = false;
    $this->postWebhook($body)->assertJson(['status' => 'ok']);

    expect($payment->refresh()->refunded_amount)->toBe(2500);
    Event::assertDispatchedTimes(PaymentRefunded::class, 1);
});

it('holds back a requested refund until Paystack processes it', function () {
    $payment = paidPayment();

    PaystackConnect::refund($payment, Money::major('40.00', 'GHS'));
    $payment->refresh();

    expect($payment->pendingRefundAmount()->toMajorString())->toBe('40.00')
        ->and($payment->refundableAmount()->toMajorString())->toBe('60.00')
        ->and(fn () => PaystackConnect::refund($payment, Money::major('70.00', 'GHS')))->toThrow(InvalidAmount::class);

    $this->postWebhook(refundWebhook($payment, 4000))->assertOk();
    $payment->refresh();

    expect($payment->pendingRefundAmount()->isZero())->toBeTrue()
        ->and($payment->refundedAmount()->toMajorString())->toBe('40.00')
        ->and($payment->refundableAmount()->toMajorString())->toBe('60.00');
});

it('frees the amount again when Paystack fails a refund', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = paidPayment();

    PaystackConnect::refund($payment);
    expect($payment->refresh()->refundableAmount()->isZero())->toBeTrue();

    $this->postWebhook(refundWebhook($payment, 10000, 'failed'))->assertOk();
    $payment->refresh();

    expect($payment->pendingRefundAmount()->isZero())->toBeTrue()
        ->and($payment->refunded_amount)->toBe(0)
        ->and($payment->refundableAmount()->toMajorString())->toBe('100.00');
    Event::assertNotDispatched(PaymentRefunded::class);
});

it('ignores refunds for payments that were never paid', function () {
    Event::fake([PaymentRefunded::class]);
    PaystackConnect::fake();
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    $this->postWebhook(refundWebhook($payment, 5000))->assertOk();

    expect($payment->refresh()->refunded_amount)->toBe(0);
    Event::assertNotDispatched(PaymentRefunded::class);
});

it('fakes a whole marketplace flow without calling Paystack', function () {
    Event::fake([PaymentSucceeded::class]);
    $fake = PaystackConnect::fake();
    $business = Business::create(['name' => 'Kofi Prints']);

    $business->connectPaystackAccount(SettlementAccount::mobileMoney('Kofi Prints', 'MTN', '0241234567', 'GHS'));
    $payment = PaystackConnect::checkout()->amount('250.00', 'GHS')->email('c@example.com')->seller($business)->create();

    $fake->assertSubaccountCreated(fn ($data) => $data['settlement_bank'] === 'MTN');
    $fake->assertCheckoutCreated(fn ($data) => $data['amount'] === 25000 && str_starts_with($data['subaccount'], 'ACCT_fake'));
    expect($business->canReceivePaystackPayments())->toBeTrue()
        ->and($business->paystackSubaccount->account_name)->toBe('Test Account Holder')
        ->and($payment->authorization_url)->toStartWith('https://checkout.paystack.com/fake_');

    // Unpaid until the customer pays.
    expect(PaystackConnect::verify($payment->reference)->status)->toBe(PaymentStatus::Pending);

    $fake->pay($payment);
    expect(PaystackConnect::verify($payment->reference)->status)->toBe(PaymentStatus::Success);
    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

    $fake->refunded($payment, Money::major('50', 'GHS'));
    expect($payment->refresh()->refundableAmount()->toMajorString())->toBe('200.00');
});

it('fakes failed payments and bank lists', function () {
    $fake = PaystackConnect::fake();
    $payment = PaystackConnect::checkout()->amount('50')->email('c@example.com')->create();

    expect($fake->fail($payment, 'Insufficient funds')->failure_reason)->toBe('Insufficient funds')
        ->and(PaystackConnect::banks()->mobileMoney('ghana')->pluck('code')->all())->toBe(['MTN', 'VOD']);
});

it('fails loudly on endpoints the fake does not cover, and its assertions fail when nothing matched', function () {
    $fake = PaystackConnect::fake();
    $fake->assertNothingSent();

    expect(fn () => PaystackConnect::client()->get('/customer'))->toThrow(PaystackException::class, 'Use Http::fake()')
        ->and(fn () => $fake->assertCheckoutCreated())->toThrow(ExpectationFailedException::class);
});
