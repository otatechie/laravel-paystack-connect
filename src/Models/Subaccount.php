<?php

namespace Otatechie\PaystackConnect\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A seller's Paystack subaccount: where their share of each payment settles.
 *
 * @property int $id
 * @property string $subaccount_code
 * @property string $business_name
 * @property string|null $settlement_bank Paystack's bank or network code.
 * @property string|null $bank_name
 * @property string $account_type
 * @property string $account_number
 * @property string $account_number_last4
 * @property string|null $account_name
 * @property string $currency
 * @property string $percentage_charge
 * @property bool $active
 * @property array<string, mixed>|null $paystack_data
 */
class Subaccount extends Model
{
    protected $table = 'paystack_subaccounts';

    protected $guarded = [];

    // Raw Paystack data stays out of JSON too; read it in code when you need it.
    protected $hidden = ['account_number', 'paystack_data'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'active' => 'boolean',
            'paystack_data' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @param Builder<Subaccount> $query */
    public function scopeFor(Builder $query, Model $owner): void
    {
        $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }

    /** "•••• 6789" */
    public function maskedAccountNumber(): string
    {
        return '•••• '.$this->account_number_last4;
    }
}
