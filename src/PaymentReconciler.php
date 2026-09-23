<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Events\PaymentAmountMismatch;
use Otatechie\PaystackConnect\Events\PaymentFailed;
use Otatechie\PaystackConnect\Events\PaymentRefunded;
use Otatechie\PaystackConnect\Events\PaymentSucceeded;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Support\Money;
use RuntimeException;

/**
 * Applies what Paystack says about a transaction to the matching payment.
 *
 * Used by both the webhook and manual verification, so a payment reaches its
 * final state exactly once, however many times Paystack tells us about it.
 */
class PaymentReconciler
{
    /**
     * @param  array<string, mixed>  $transaction  Paystack's transaction data.
     * @return Payment|null The payment, or null when no payment has this reference.
     */
    public function reconcile(array $transaction): ?Payment
    {
        $event = null;

        $payment = DB::transaction(function () use ($transaction, &$event) {
            $payment = Payment::query()
                ->where('reference', $transaction['reference'] ?? null)
                ->lockForUpdate()
                ->first();

            // Already settled: a retried webhook or a second verification. Nothing to do.
            if (! $payment || $payment->status->isFinal()) {
                return $payment;
            }

            $status = $transaction['status'] ?? null;

            if ($status === 'success') {
                if (! isset($transaction['amount'], $transaction['currency'])) {
                    throw new RuntimeException('Paystack reported a successful charge without an amount or currency; not settling the payment.');
                }

                // When the merchant passes Paystack's fee on to the customer,
                // "amount" includes it and "requested_amount" is what we asked for.
                $charged = (int) ($transaction['requested_amount'] ?? $transaction['amount']);

                $amountMatches = $charged === $payment->amount
                    && strtoupper((string) $transaction['currency']) === $payment->currency;

                if (! $amountMatches) {
                    $payment->update([
                        'status' => PaymentStatus::AmountMismatch,
                        'failure_reason' => "Paystack charged {$transaction['currency']} {$transaction['amount']} (minor units); expected {$payment->currency} {$payment->amount}.",
                        'paystack_data' => $transaction,
                    ]);

                    $event = new PaymentAmountMismatch($payment, $transaction);

                    return $payment;
                }

                $payment->update([
                    'status' => PaymentStatus::Success,
                    'paystack_transaction_id' => $transaction['id'] ?? null,
                    'paystack_fee' => $transaction['fees'] ?? null,
                    'channel' => $transaction['channel'] ?? null,
                    'paid_at' => $transaction['paid_at'] ?? now(),
                    'paystack_data' => $transaction,
                ]);

                $event = new PaymentSucceeded($payment);

                return $payment;
            }

            // Only a pending payment can fail, so PaymentFailed fires once.
            // "abandoned" is not a failure: Paystack reports it for every
            // checkout the customer hasn't paid yet, so it stays pending.
            if ($payment->isPending() && in_array($status, ['failed', 'reversed'], true)) {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => $transaction['gateway_response'] ?? $status,
                    'paystack_data' => $transaction,
                ]);

                $event = new PaymentFailed($payment);
            }

            return $payment;
        });

        // Dispatch after the transaction commits, so listeners see saved data.
        if ($event) {
            event($event);
        }

        return $payment;
    }

    /**
     * Records a refund that Paystack has processed.
     *
     * @param  array<string, mixed>  $refund  Paystack's refund data.
     * @return Payment|null The payment, or null when no payment has this reference.
     */
    public function reconcileRefund(array $refund): ?Payment
    {
        return $this->applyRefund($refund, function (Payment $payment, int $amount) {
            // Never record more than was paid.
            $amount = min($amount, $payment->amount - $payment->refunded_amount);

            if ($amount <= 0) {
                return null;
            }

            $refunded = $payment->refunded_amount + $amount;

            $payment->update([
                'refunded_amount' => $refunded,
                'refunded_at' => now(),
                'status' => $refunded >= $payment->amount ? PaymentStatus::Refunded : $payment->status,
            ]);

            return new PaymentRefunded($payment, Money::minor($amount, $payment->currency));
        });
    }

    /**
     * Frees the amount of a refund that Paystack failed, so it can be refunded again.
     *
     * @param  array<string, mixed>  $refund  Paystack's refund data.
     */
    public function releaseRefund(array $refund): ?Payment
    {
        return $this->applyRefund($refund, fn () => null);
    }

    /**
     * A refund whose Paystack id was already recorded is skipped, so a
     * retried webhook counts once.
     *
     * @param  array<string, mixed>  $refund
     * @param  callable(Payment, int): (PaymentRefunded|null)  $apply
     */
    private function applyRefund(array $refund, callable $apply): ?Payment
    {
        $event = null;

        $payment = DB::transaction(function () use ($refund, $apply, &$event) {
            $payment = Payment::query()
                ->where('reference', $refund['transaction_reference'] ?? $refund['transaction']['reference'] ?? null)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return null;
            }

            if (! in_array($payment->status, [PaymentStatus::Success, PaymentStatus::Refunded], true)) {
                Log::channel(config('paystack-connect.log_channel'))->warning('Paystack sent a refund for a payment that is not marked paid; it was not recorded.', [
                    'reference' => $payment->reference, 'status' => $payment->status->value, 'refund_id' => $refund['id'] ?? null,
                ]);

                return $payment;
            }

            $refundId = $refund['id'] ?? null;
            $refundIds = $payment->refund_ids ?? [];

            if ($refundId !== null && in_array($refundId, $refundIds, true)) {
                return $payment;
            }

            $event = $apply($payment, (int) ($refund['amount'] ?? 0));

            // Whether processed or failed, this refund is no longer pending.
            // A refund we never requested (made from the dashboard) holds nothing.
            $pending = $payment->pending_refunds ?? [];
            unset($pending[$refundId]);

            $payment->update([
                'pending_refunds' => $pending ?: null,
                'refund_ids' => $refundId !== null ? [...$refundIds, $refundId] : $refundIds,
            ]);

            return $payment;
        });

        if ($event) {
            event($event);
        }

        return $payment;
    }
}
