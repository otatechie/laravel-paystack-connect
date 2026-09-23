<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Support\Money;

/** Paystack returned money to the customer: all of it, or part of it. */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly Money $amount,
    ) {}
}
