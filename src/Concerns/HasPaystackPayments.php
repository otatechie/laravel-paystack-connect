<?php

namespace Otatechie\PaystackConnect\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Models\Payment;

/**
 * Add to the thing being paid for: an invoice, order or booking. Payments
 * link to it with ->for($invoice) at checkout.
 *
 * @mixin Model
 */
trait HasPaystackPayments
{
    /** @return MorphMany<Payment, $this> */
    public function paystackPayments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** The most recent attempt, whatever its status. */
    public function latestPaystackPayment(): ?Payment
    {
        return $this->paystackPayments()->latest('id')->first();
    }

    /** Whether a payment for it succeeded. A fully refunded payment doesn't count; a partly refunded one does. */
    public function isPaidOnPaystack(): bool
    {
        return $this->paystackPayments()->where('status', PaymentStatus::Success)->exists();
    }
}
