<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Payment;

/** The charge was declined. The customer can still pay on the same checkout. */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
