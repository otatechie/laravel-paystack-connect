<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
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
        // Lock the row so two refunds can't both pass the check, and so the
        // check uses what webhooks have recorded, not a stale model.
        $refund = DB::transaction(function () use ($payment, $amount) {
            $fresh = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isSuccessful()) {
                throw new InvalidArgumentException('Only a successful payment can be refunded.');
            }

            $amount ??= $fresh->refundableAmount();

            if ($amount->currency !== $fresh->currency) {
                throw InvalidAmount::currencyMismatch($amount->currency, $fresh->currency);
            }

            if ($amount->isZero() || $amount->minor > $fresh->refundableAmount()->minor) {
                throw new InvalidAmount("Refund must be above zero and at most {$fresh->refundableAmount()}.");
            }

            $refund = $this->client()->post('/refund', [
                'transaction' => $fresh->reference,
                'amount' => $amount->minor,
            ])['data'];

            if (isset($refund['id'])) {
                $fresh->update(['pending_refunds' => [...($fresh->pending_refunds ?? []), $refund['id'] => $amount->minor]]);
            }

            return $refund;
        });

        $payment->refresh();

        return $refund;
    }

    public function client(): PaystackClient
    {
        return $this->container->make(PaystackClient::class);
    }
}
