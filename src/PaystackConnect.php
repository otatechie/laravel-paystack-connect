<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Otatechie\PaystackConnect\Exceptions\InvalidAmount;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Support\Money;

/**
 * The entry point, usually reached through the PaystackConnect facade.
 */
class PaystackConnect
{
    public function __construct(private readonly Container $container) {}

    public function checkout(): Checkout
    {
        return $this->container->make(Checkout::class);
    }

    public function banks(): Banks
    {
        return $this->container->make(Banks::class);
    }

    public function subaccounts(): Subaccounts
    {
        return $this->container->make(Subaccounts::class);
    }

    public function fees(): Fees
    {
        return $this->container->make(Fees::class);
    }

    public function feeFor(Money $amount): Money
    {
        return $this->fees()->for($amount);
    }

    /**
     * Ask Paystack for a transaction's status and update the payment.
     *
     * Call this on your callback page for instant feedback. Webhooks remain
     * the source of truth, and both paths settle a payment only once.
     */
    public function verify(string $reference): ?Payment
    {
        $data = $this->client()->get('/transaction/verify/'.rawurlencode($reference))['data'];

        return $this->container->make(PaymentReconciler::class)->reconcile($data);
    }

    /**
     * Ask Paystack to refund a successful payment, in full or in part.
     *
     * Paystack processes refunds asynchronously. Until it does, the amount is
     * held as pending so it can't be refunded twice. The payment is updated,
     * and PaymentRefunded dispatched, when the refund.processed webhook arrives.
     *
     * @return array<string, mixed> Paystack's refund data.
     */
    public function refund(Payment $payment, ?Money $amount = null): array
    {
        if (! $payment->isSuccessful()) {
            throw new InvalidArgumentException('Only a successful payment can be refunded.');
        }

        $amount ??= $payment->refundableAmount();

        if ($amount->currency !== $payment->currency) {
            throw InvalidAmount::currencyMismatch($amount->currency, $payment->currency);
        }

        if ($amount->isZero() || $amount->minor > $payment->refundableAmount()->minor) {
            throw new InvalidAmount("Refund must be above zero and at most {$payment->refundableAmount()}.");
        }

        $refund = $this->client()->post('/refund', [
            'transaction' => $payment->reference,
            'amount' => $amount->minor,
        ])['data'];

        $payment->increment('refund_pending', $amount->minor);

        return $refund;
    }

    public function client(): PaystackClient
    {
        return $this->container->make(PaystackClient::class);
    }
}
