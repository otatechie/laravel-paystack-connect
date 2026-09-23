<?php

namespace Otatechie\PaystackConnect\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    /** Paystack reported success, but the amount or currency differed from what we asked for. */
    case AmountMismatch = 'amount_mismatch';

    /** The full amount went back to the customer. A partial refund stays Success. */
    case Refunded = 'refunded';

    /**
     * Nothing Paystack reports later can change it. A failed or abandoned
     * payment is not final: the customer can still pay on the same checkout.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::AmountMismatch, self::Refunded], true);
    }
}
