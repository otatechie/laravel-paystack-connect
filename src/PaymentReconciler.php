<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Support\Facades\DB;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Events\PaymentAmountMismatch;
use Otatechie\PaystackConnect\Events\PaymentFailed;
use Otatechie\PaystackConnect\Events\PaymentRefunded;
use Otatechie\PaystackConnect\Events\PaymentSucceeded;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Support\Money;

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
                $amountMatches = (int) $transaction['amount'] === $payment->amount
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
            if ($payment->isPending() && in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                $payment->update([
                    'status' => $status === 'abandoned' ? PaymentStatus::Abandoned : PaymentStatus::Failed,
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
     * Records a refund that Paystack has processed. A refund whose Paystack
     * id was already recorded is skipped, so a retried webhook counts once.
     *
     * @param  array<string, mixed>  $refund  Paystack's refund data.
     * @return Payment|null The payment, or null when no payment has this reference.
     */
    public function reconcileRefund(array $refund): ?Payment
    {
        $event = null;

        $payment = DB::transaction(function () use ($refund, &$event) {
            $payment = Payment::query()
                ->where('reference', $refund['transaction_reference'] ?? $refund['transaction']['reference'] ?? null)
                ->lockForUpdate()
                ->first();

            if (! $payment || ! in_array($payment->status, [PaymentStatus::Success, PaymentStatus::Refunded], true)) {
                return $payment;
            }

            $refundId = $refund['id'] ?? null;
            $refundIds = $payment->refund_ids ?? [];

            if ($refundId !== null && in_array($refundId, $refundIds, true)) {
                return $payment;
            }

            // Never record more than was paid.
            $amount = min((int) ($refund['amount'] ?? 0), $payment->refundableAmount()->minor);

            if ($amount <= 0) {
                return $payment;
            }

            $refunded = $payment->refunded_amount + $amount;

            $payment->update([
                'refunded_amount' => $refunded,
                'refunded_at' => now(),
                'refund_ids' => $refundId === null ? $payment->refund_ids : [...$refundIds, $refundId],
                'status' => $refunded >= $payment->amount ? PaymentStatus::Refunded : $payment->status,
            ]);

            $event = new PaymentRefunded($payment, Money::minor($amount, $payment->currency));

            return $payment;
        });

        if ($event) {
            event($event);
        }

        return $payment;
    }
}
