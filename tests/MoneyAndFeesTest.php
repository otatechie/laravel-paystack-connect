<?php

use Otatechie\PaystackConnect\Exceptions\InvalidAmount;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Fees;
use Otatechie\PaystackConnect\Support\Money;

it('parses major-unit strings exactly', function (string $input, int $minor) {
    expect(Money::major($input, 'GHS')->minor)->toBe($minor);
})->with([
    ['19.99', 1999],
    ['250', 25000],
    ['1.5', 150],
    ['0.01', 1],
    ['1000000.10', 100000010],
]);

it('never truncates floats the way (int) ($amount * 100) does', function () {
    // (int) (19.99 * 100) === 1998, one pesewa short. This was a live bug.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(Money::major(19.99, 'GHS')->minor)->toBe(1999)
        ->and(Money::major(0.29, 'GHS')->minor)->toBe(29);
});

it('rejects amounts it cannot parse exactly', function (string $input) {
    Money::major($input, 'GHS');
})->with(['19.999', '1,000', '-5', 'abc', ''])->throws(InvalidAmount::class);

it('formats and compares amounts', function () {
    $money = Money::minor(1999, 'ghs');

    expect($money->currency)->toBe('GHS')
        ->and($money->toMajorString())->toBe('19.99')
        ->and((string) $money)->toBe('GHS 19.99')
        ->and($money->equals(Money::major('19.99', 'GHS')))->toBeTrue();
});

it('refuses to mix currencies', function () {
    Money::minor(100, 'GHS')->add(Money::minor(100, 'NGN'));
})->throws(InvalidAmount::class);

function useFeeRules(array $rules): void
{
    config()->set('paystack-connect.fees', $rules);
    app()->forgetInstance(Fees::class);
}

it('charges the percentage fee within the min and max', function (string $amount, string $fee) {
    useFeeRules(['default' => ['percentage' => 2.5], 'currencies' => ['GHS' => ['min' => 5, 'max' => 50]]]);

    expect(PaystackConnect::feeFor(Money::major($amount, 'GHS'))->toMajorString())->toBe($fee);
})->with([
    'min applies' => ['100.00', '5.00'],          // 2.5% = 2.50, raised to the GHS 5 minimum
    'percentage applies' => ['1000.00', '25.00'],
    'max applies' => ['4000.00', '50.00'],        // 2.5% = 100, capped at GHS 50
]);

it('never takes a fee larger than the payment', function () {
    useFeeRules(['default' => ['percentage' => 2.5, 'min' => 5]]);

    expect(PaystackConnect::feeFor(Money::major('2.00', 'GHS'))->toMajorString())->toBe('2.00');
});

it('takes a small, fair fee on small payments by default', function () {
    // A GHS 10 sale: GHS 0.25 to the platform, GHS 9.75 to the seller.
    expect(PaystackConnect::feeFor(Money::major('10.00', 'GHS'))->toMajorString())->toBe('0.25');
});

it('sets default fees that always cover Paystack\'s own fee, so the platform never loses money', function (string $currency, Closure $paystackFee) {
    foreach ([1, 5, 10, 50, 100, 499, 2500, 2501, 5000, 9999, 10000, 20000, 150000, 1000000] as $major) {
        $amount = Money::major((string) $major, $currency);
        $ours = PaystackConnect::feeFor($amount)->minor;
        $theirs = (int) ceil($paystackFee($amount->minor));

        // Paystack can't take more than the payment itself.
        expect($ours)->toBeGreaterThanOrEqual(min($theirs, $amount->minor), "{$currency} {$major}: ours {$ours}, Paystack {$theirs}");
    }
})->with([
    // Rates from Paystack's published pricing, in minor units.
    'GHS: 1.95%' => ['GHS', fn (int $m) => $m * 0.0195],
    'NGN: 1.5% + NGN 100 over NGN 2,500, capped at NGN 2,000' => ['NGN', fn (int $m) => min($m * 0.015 + ($m >= 250000 ? 10000 : 0), 200000)],
    'KES: 2.9% on cards (M-Pesa is 1.5%)' => ['KES', fn (int $m) => $m * 0.029],
    'ZAR: 2.9% + R1, plus 15% VAT' => ['ZAR', fn (int $m) => ($m * 0.029 + 100) * 1.15],
]);

it('adds a flat fee on top of the percentage', function () {
    useFeeRules(['default' => ['percentage' => 1.5, 'flat' => '1.00']]);

    expect(PaystackConnect::feeFor(Money::major('200.00', 'USD'))->toMajorString())->toBe('4.00');
});

it('refuses fractions of currencies that have no subunit', function () {
    // Paystack still takes these ×100, but silently drops any fraction.
    expect(Money::major('1000', 'XOF')->minor)->toBe(100000)
        ->and(fn () => Money::major('10.50', 'XOF'))->toThrow(InvalidAmount::class)
        ->and(fn () => Money::minor(1050, 'RWF'))->toThrow(InvalidAmount::class);
});

it('rounds fees to whole units in currencies without a subunit', function () {
    // 2.5% of XOF 1,010 is 25.25, which Paystack can't charge.
    expect(PaystackConnect::feeFor(Money::major('1010', 'XOF'))->minor)->toBe(2500);
});
