<?php

namespace Otatechie\PaystackConnect;

use Otatechie\PaystackConnect\Support\Money;

/**
 * Works out your platform fee for a payment from the rules in config:
 * a percentage plus a flat amount, kept between a minimum and a maximum,
 * and never more than the payment itself.
 */
class Fees
{
    /** @param array{default?: array<string, mixed>, currencies?: array<string, array<string, mixed>>} $rules */
    public function __construct(private readonly array $rules) {}

    public function for(Money $amount): Money
    {
        $rule = $this->ruleFor($amount->currency);

        // Round to what Paystack can charge: a pesewa, or a whole franc for XOF.
        $unit = Money::smallestUnit($amount->currency);

        $fee = Money::minor(
            (int) round($amount->minor * ((float) ($rule['percentage'] ?? 0)) / 100 / $unit) * $unit,
            $amount->currency,
        );

        if (! empty($rule['flat'])) {
            $fee = $fee->add(Money::major((string) $rule['flat'], $amount->currency));
        }

        if (isset($rule['min'])) {
            $fee = $fee->max(Money::major((string) $rule['min'], $amount->currency));
        }

        if (isset($rule['max'])) {
            $fee = $fee->min(Money::major((string) $rule['max'], $amount->currency));
        }

        return $fee->min($amount);
    }

    /** @return array<string, mixed> */
    private function ruleFor(string $currency): array
    {
        return array_merge(
            $this->rules['default'] ?? [],
            $this->rules['currencies'][strtoupper($currency)] ?? [],
        );
    }
}
