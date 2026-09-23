<?php

namespace Otatechie\PaystackConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Support\Money;

/**
 * One checkout: what the customer pays, your platform fee, and where it stands.
 * Amounts are stored in minor units.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $subaccount_id
 * @property string $email
 * @property int $amount
 * @property int $platform_fee
 * @property int|null $paystack_fee
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $access_code
 * @property string|null $authorization_url
 * @property int|null $paystack_transaction_id
 * @property string|null $channel
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $paystack_data
 * @property Carbon|null $paid_at
 * @property int $refunded_amount
 * @property Carbon|null $refunded_at
 * @property array<int|string, int>|null $pending_refunds Refunds requested but not yet processed, by Paystack refund id.
 * @property list<int|string>|null $refund_ids Paystack ids of the refunds already recorded.
 */
class Payment extends Model
{
    protected $table = 'paystack_payments';

    protected $guarded = [];

    // access_code opens the checkout; paystack_data holds card and customer details.
    protected $hidden = ['access_code', 'paystack_data'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'platform_fee' => 'integer',
            'paystack_fee' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paystack_data' => 'array',
            'paid_at' => 'datetime',
            'refunded_amount' => 'integer',
            'pending_refunds' => 'array',
            'refunded_at' => 'datetime',
            'refund_ids' => 'array',
        ];
    }

    /** @return BelongsTo<Subaccount, $this> */
    public function subaccount(): BelongsTo
    {
        return $this->belongsTo(Subaccount::class);
    }

    /**
     * The thing being paid for, such as an invoice or an order.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function total(): Money
    {
        return Money::minor($this->amount, $this->currency);
    }

    public function platformFee(): Money
    {
        return Money::minor($this->platform_fee, $this->currency);
    }

    /** What the seller receives before Paystack's own fee. */
    public function sellerShare(): Money
    {
        return $this->total()->subtract($this->platformFee());
    }

    public function refundedAmount(): Money
    {
        return Money::minor($this->refunded_amount, $this->currency);
    }

    /** Refunds requested from Paystack that it hasn't processed yet. */
    public function pendingRefundAmount(): Money
    {
        return Money::minor((int) array_sum($this->pending_refunds ?? []), $this->currency);
    }

    /** What can still be refunded: not refunded, and not already requested. */
    public function refundableAmount(): Money
    {
        return $this->total()->subtract($this->refundedAmount())->subtract($this->pendingRefundAmount());
    }

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Success;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }
}
