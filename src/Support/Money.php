<?php

namespace Otatechie\PaystackConnect\Support;

use JsonSerializable;
use Otatechie\PaystackConnect\Exceptions\InvalidAmount;
use Stringable;

/**
 * An amount in minor units (pesewas, kobo, cents): the unit Paystack's API uses.
 *
 * Amounts are never stored as floats, so 19.99 is always 1999 and never
 * 1998.9999999999998.
 */
final class Money implements JsonSerializable, Stringable
{
    /** Paystack expresses every supported currency in hundredths. */
    public const SUBUNITS = 100;

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function minor(int $minor, ?string $currency = null): self
    {
        if ($minor < 0) {
            throw InvalidAmount::negative();
        }

        return new self($minor, self::currency($currency));
    }

    /**
     * Build from a major-unit amount, such as "19.99" or 19.99.
     *
     * Strings are parsed exactly. Floats are rounded to the nearest subunit.
     */
    public static function major(string|int|float $amount, ?string $currency = null): self
    {
        $currency = self::currency($currency);

        if (is_int($amount)) {
            return self::minor($amount * self::SUBUNITS, $currency);
        }

        if (is_float($amount)) {
            return self::minor((int) round($amount * self::SUBUNITS), $currency);
        }

        $amount = trim($amount);

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw InvalidAmount::unparseable($amount);
        }

        $fraction = str_pad($parts[2] ?? '', 2, '0');

        return self::minor(((int) $parts[1]) * self::SUBUNITS + (int) $fraction, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::minor($this->minor - $other->minor, $this->currency);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor ? $this : $other;
    }

    public function max(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor ? $this : $other;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /** "19.99" */
    public function toMajorString(): string
    {
        return intdiv($this->minor, self::SUBUNITS).'.'.str_pad((string) ($this->minor % self::SUBUNITS), 2, '0', STR_PAD_LEFT);
    }

    /** "GHS 19.99" */
    public function __toString(): string
    {
        return $this->currency.' '.$this->toMajorString();
    }

    /** @return array{amount: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->minor, 'currency' => $this->currency, 'formatted' => (string) $this];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw InvalidAmount::currencyMismatch($this->currency, $other->currency);
        }
    }

    private static function currency(?string $currency): string
    {
        return strtoupper($currency ?? (string) config('paystack-connect.currency', 'GHS'));
    }
}
