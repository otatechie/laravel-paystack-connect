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

it('charges the percentage fee within the min and max for each currency', function (string $amount, string $currency, string $fee) {
    expect(PaystackConnect::feeFor(Money::major($amount, $currency))->toMajorString())->toBe($fee);
})->with([
    'min applies' => ['100.00', 'GHS', '5.00'],    // 2.5% = 2.50, raised to the GHS 5 minimum
    'percentage applies' => ['1000.00', 'GHS', '25.00'],
    'max applies' => ['4000.00', 'GHS', '50.00'],  // 2.5% = 100, capped at GHS 50
    'NGN rules' => ['10000.00', 'NGN', '500.00'],
]);

it('never takes a fee larger than the payment', function () {
    expect(PaystackConnect::feeFor(Money::major('2.00', 'GHS'))->toMajorString())->toBe('2.00');
});

it('adds a flat fee on top of the percentage', function () {
    config()->set('paystack-connect.fees', ['default' => ['percentage' => 1.5, 'flat' => '1.00']]);
    app()->forgetInstance(Fees::class);

    expect(PaystackConnect::feeFor(Money::major('200.00', 'USD'))->toMajorString())->toBe('4.00');
});
