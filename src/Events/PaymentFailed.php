<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Payment;

/** The payment failed or was abandoned. */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
