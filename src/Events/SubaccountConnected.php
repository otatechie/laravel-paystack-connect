<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Otatechie\PaystackConnect\Models\Subaccount;

/** A seller's subaccount was created or updated on Paystack. */
class SubaccountConnected
{
    use Dispatchable;

    public function __construct(public readonly Subaccount $subaccount) {}
}
