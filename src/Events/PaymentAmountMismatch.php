<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Payment;

/**
 * Paystack reported a successful charge, but for a different amount or currency
 * than the payment asked for. The payment is not marked paid; review it.
 */
class PaymentAmountMismatch
{
    use Dispatchable;

    /** @param array<string, mixed> $transaction Paystack's transaction data. */
    public function __construct(public readonly Payment $payment, public readonly array $transaction) {}
}
