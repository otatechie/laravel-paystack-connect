<?php

namespace Otatechie\PaystackConnect\Testing;

use Illuminate\Support\Str;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\PaymentReconciler;
use Otatechie\PaystackConnect\Support\Money;
use PHPUnit\Framework\Assert;

/**
 * Stands in for Paystack in your app's tests. Start it with PaystackConnect::fake().
 *
 *     $paystack = PaystackConnect::fake();
 *
 *     $this->post('/invoices/1/pay');
 *
 *     $paystack->assertCheckoutCreated(fn ($data) => $data['amount'] === 25000);
 *     $paystack->pay(Payment::first()); // as if the charge.success webhook arrived
 */
class PaystackFake extends PaystackClient
{
    /** @var list<array{method: string, uri: string, data: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, string> Transaction status to report per reference. */
    private array $statuses = [];

    public function __construct(private readonly PaymentReconciler $reconciler)
    {
        parent::__construct('sk_test_fake');
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->respond('GET', $uri, $query);
    }

    public function post(string $uri, array $data = []): array
    {
        return $this->respond('POST', $uri, $data);
    }

    public function put(string $uri, array $data = []): array
    {
        return $this->respond('PUT', $uri, $data);
    }

    /** Settle the payment as paid, as the charge.success webhook would. */
    public function pay(Payment $payment): Payment
    {
        $this->statuses[$payment->reference] = 'success';

        return $this->reconciler->reconcile($this->transaction($payment));
    }

    /** Settle the payment as failed, as a failed charge would. */
    public function fail(Payment $payment, string $reason = 'Declined'): Payment
    {
        $this->statuses[$payment->reference] = 'failed';

        return $this->reconciler->reconcile([...$this->transaction($payment), 'gateway_response' => $reason]);
    }

    /** Record a refund, as the refund.processed webhook would. Refunds everything not yet refunded by default. */
    public function refunded(Payment $payment, ?Money $amount = null): Payment
    {
        return $this->reconciler->reconcileRefund([
            'id' => random_int(1_000_000, 9_999_999),
            'transaction_reference' => $payment->reference,
            'amount' => ($amount ?? $payment->total()->subtract($payment->refundedAmount()))->minor,
            'currency' => $payment->currency,
            'status' => 'processed',
        ]);
    }

    /** @param (callable(array<string, mixed>): bool)|null $callback Receives what was sent to Paystack. */
    public function assertCheckoutCreated(?callable $callback = null): void
    {
        $this->assertSent('POST', '/transaction/initialize', $callback, 'No matching checkout was sent to Paystack.');
    }

    /** @param (callable(array<string, mixed>): bool)|null $callback Receives what was sent to Paystack. */
    public function assertSubaccountCreated(?callable $callback = null): void
    {
        $this->assertSent('POST', '/subaccount', $callback, 'No matching subaccount was created on Paystack.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->requests, 'Requests were sent to Paystack.');
    }

    /** @return list<array{method: string, uri: string, data: array<string, mixed>}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @param (callable(array<string, mixed>): bool)|null $callback */
    private function assertSent(string $method, string $uri, ?callable $callback, string $message): void
    {
        $matches = array_filter(
            $this->requests,
            fn ($request) => $request['method'] === $method && $request['uri'] === $uri
                && ($callback === null || $callback($request['data'])),
        );

        Assert::assertNotEmpty($matches, $message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function respond(string $method, string $uri, array $data): array
    {
        $this->requests[] = ['method' => $method, 'uri' => $uri, 'data' => $data];

        $body = match (true) {
            $method === 'POST' && $uri === '/transaction/initialize' => [
                'authorization_url' => 'https://checkout.paystack.com/fake_'.$data['reference'],
                'access_code' => 'fake_'.$data['reference'],
                'reference' => $data['reference'],
            ],
            $method === 'GET' && str_starts_with($uri, '/transaction/verify/') => $this->verify(rawurldecode(substr($uri, 20))),
            $method === 'POST' && $uri === '/subaccount' => $this->subaccount('ACCT_fake'.Str::lower(Str::random(10)), $data),
            $method === 'PUT' && str_starts_with($uri, '/subaccount/') => $this->subaccount(substr($uri, 12), $data),
            $method === 'GET' && $uri === '/subaccount' => [],
            $method === 'GET' && $uri === '/bank/resolve' => [
                'account_number' => $data['account_number'],
                'account_name' => 'Test Account Holder',
            ],
            $method === 'GET' && $uri === '/bank' => $this->banks($data['type'] ?? null),
            $method === 'POST' && $uri === '/refund' => [
                'id' => random_int(1_000_000, 9_999_999),
                'transaction' => ['reference' => $data['transaction']],
                'amount' => $data['amount'],
                'status' => 'pending',
            ],
            default => throw new PaystackException("PaystackFake doesn't handle {$method} {$uri}. Use Http::fake() for that endpoint."),
        };

        return ['status' => true, 'message' => 'Fake response', 'data' => $body, 'meta' => ['pageCount' => 1]];
    }

    /** @return array<string, mixed> */
    private function verify(string $reference): array
    {
        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            throw new PaystackException('Transaction reference not found.', 400);
        }

        return $this->transaction($payment);
    }

    /** @return array<string, mixed> */
    private function transaction(Payment $payment): array
    {
        return [
            'id' => random_int(1_000_000, 9_999_999),
            // Paystack reports "abandoned" for a checkout that hasn't been paid yet.
            'status' => $this->statuses[$payment->reference] ?? 'abandoned',
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'channel' => 'card',
            'fees' => 0,
            'paid_at' => now()->toIso8601String(),
            'gateway_response' => 'Successful',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function subaccount(string $code, array $data): array
    {
        return [...$data, 'subaccount_code' => $code, 'active' => true];
    }

    /** @return list<array{name: string, code: string, type: string, currency: string}> */
    private function banks(?string $type): array
    {
        $banks = [
            ['name' => 'Test Bank', 'code' => '280100', 'type' => 'ghipss', 'currency' => 'GHS'],
            ['name' => 'MTN', 'code' => 'MTN', 'type' => 'mobile_money', 'currency' => 'GHS'],
            ['name' => 'Telecel Cash', 'code' => 'VOD', 'type' => 'mobile_money', 'currency' => 'GHS'],
        ];

        return array_values(array_filter($banks, fn ($bank) => $type === null || $bank['type'] === $type));
    }
}
