<?php

namespace Otatechie\PaystackConnect\Exceptions;

use InvalidArgumentException;

class InvalidAmount extends InvalidArgumentException
{
    public static function negative(): self
    {
        return new self('An amount cannot be negative.');
    }

    public static function unparseable(string $amount): self
    {
        return new self("\"{$amount}\" is not a valid amount. Use digits with up to two decimals, like \"19.99\".");
    }

    public static function currencyMismatch(string $a, string $b): self
    {
        return new self("Cannot combine {$a} and {$b} amounts.");
    }
}
