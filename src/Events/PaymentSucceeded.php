<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Payment;

/** Paystack confirmed the payment, and the amount and currency match what was charged. */
class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
