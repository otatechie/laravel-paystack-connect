<?php

namespace Otatechie\PaystackConnect\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Support\SettlementAccount;

/**
 * Add to the model that gets paid: a business, vendor, organiser or school.
 *
 * @mixin Model
 */
trait HasPaystackSubaccount
{
    /** @return MorphOne<Subaccount, $this> */
    public function paystackSubaccount(): MorphOne
    {
        return $this->morphOne(Subaccount::class, 'owner');
    }

    public function connectPaystackAccount(SettlementAccount $account, ?float $percentageCharge = null): Subaccount
    {
        return PaystackConnect::subaccounts()->connect($this, $account, $percentageCharge);
    }

    public function canReceivePaystackPayments(): bool
    {
        return (bool) $this->paystackSubaccount?->active;
    }
}
