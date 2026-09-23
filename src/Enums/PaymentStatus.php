<?php

namespace Otatechie\PaystackConnect\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    /** The charge was declined. The customer can still pay on the same checkout. */
    case Failed = 'failed';

    /** Paystack reported success, but the amount or currency differed from what we asked for. */
    case AmountMismatch = 'amount_mismatch';

    /** The full amount went back to the customer. A partial refund stays Success. */
    case Refunded = 'refunded';

    /**
     * Nothing Paystack reports later can change it. A failed payment is not
     * final: the customer can still pay on the same checkout.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::AmountMismatch, self::Refunded], true);
    }
}
