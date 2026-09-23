<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Otatechie\PaystackConnect\Events\SubaccountConnected;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Support\SettlementAccount;
use Otatechie\PaystackConnect\Tests\Fixtures\Business;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeSubaccountApi(): void
{
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'KOFI MENSAH', 'account_number' => '0241234567']]),
        'api.paystack.co/subaccount/ACCT_abc' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_abc', 'active' => true]]),
        'api.paystack.co/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_abc', 'active' => true, 'settlement_bank' => 'MTN']]),
    ]);
}

it('verifies the account holder and creates a subaccount with the bank code', function () {
    fakeSubaccountApi();
    Event::fake([SubaccountConnected::class]);

    $business = Business::create(['name' => 'Kofi Prints']);

    $subaccount = $business->connectPaystackAccount(
        SettlementAccount::mobileMoney('Kofi Prints', 'MTN', '0241234567', 'ghs', 'MTN Mobile Money'),
    );

    expect($subaccount->subaccount_code)->toBe('ACCT_abc')
        ->and($subaccount->account_name)->toBe('KOFI MENSAH')
        ->and($subaccount->account_number_last4)->toBe('4567')
        ->and($subaccount->account_number)->toBe('0241234567')
        ->and($subaccount->currency)->toBe('GHS')
        ->and($business->canReceivePaystackPayments())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/subaccount'
        && $request['settlement_bank'] === 'MTN'
        && json_decode($request['metadata'], true) === ['owner_type' => Business::class, 'owner_id' => $business->id]);

    Event::assertDispatched(SubaccountConnected::class);
});

it('never sends placeholder contact details', function () {
    fakeSubaccountApi();

    Business::create(['name' => 'Kofi Prints'])
        ->connectPaystackAccount(SettlementAccount::bank('Kofi Prints', '300335', '1234567890', 'GHS'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/subaccount')
        && ! isset($request['primary_contact_email'], $request['primary_contact_phone']));
});

it('updates the same subaccount when a seller changes accounts instead of creating a duplicate', function () {
    fakeSubaccountApi();

    $business = Business::create(['name' => 'Kofi Prints']);
    $business->connectPaystackAccount(SettlementAccount::bank('Kofi Prints', '300335', '1234567890', 'GHS'));
    $business->connectPaystackAccount(SettlementAccount::bank('Kofi Prints', '300312', '9999999999', 'GHS'));

    expect(Subaccount::count())->toBe(1)
        ->and(Subaccount::first()->account_number_last4)->toBe('9999');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/subaccount/ACCT_abc'));
});

it('does not create a subaccount when Paystack cannot resolve the account', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name.'], 422),
    ]);

    expect(fn () => Business::create(['name' => 'Ama Foods'])
        ->connectPaystackAccount(SettlementAccount::bank('Ama Foods', '300335', '0000000001', 'GHS')))
        ->toThrow(PaystackException::class, 'Could not resolve account name.');

    expect(Subaccount::count())->toBe(0);
});

it('imports every page of existing subaccounts, not just the first 50', function () {
    $business = Business::create(['name' => 'Kofi Prints']);
    $page = fn (int $from, int $to) => collect(range($from, $to))->map(fn ($i) => [
        'subaccount_code' => "ACCT_{$i}",
        'business_name' => "Seller {$i}",
        'settlement_bank' => 'GCB Bank',
        'account_number' => '00000'.$i,
        'currency' => 'GHS',
        'metadata' => $i === 1 ? json_encode(['owner_type' => Business::class, 'owner_id' => $business->id]) : null,
    ])->all();

    Http::fake([
        'api.paystack.co/subaccount?perPage=100&page=1' => Http::response(['status' => true, 'data' => $page(1, 100), 'meta' => ['pageCount' => 2]]),
        'api.paystack.co/subaccount?perPage=100&page=2' => Http::response(['status' => true, 'data' => $page(101, 130), 'meta' => ['pageCount' => 2]]),
    ]);

    expect(PaystackConnect::subaccounts()->import())->toBe(130)
        ->and(Subaccount::count())->toBe(130)
        ->and($business->fresh()->paystackSubaccount->subaccount_code)->toBe('ACCT_1');
});

it('lists every bank by following Paystack\'s cursor', function () {
    Http::fake([
        'api.paystack.co/bank?country=ghana&perPage=100&use_cursor=true' => Http::response(['status' => true, 'data' => [['name' => 'GCB Bank', 'code' => '300335']], 'meta' => ['next' => 'CURSOR2']]),
        'api.paystack.co/bank?country=ghana&perPage=100&use_cursor=true&next=CURSOR2' => Http::response(['status' => true, 'data' => [['name' => 'Telecel Cash', 'code' => 'VOD', 'type' => 'mobile_money']], 'meta' => ['next' => null]]),
    ]);

    $banks = PaystackConnect::banks()->list('Ghana');

    expect($banks->pluck('code')->all())->toBe(['300335', 'VOD'])
        ->and(PaystackConnect::banks()->find('ghana', 'VOD')['name'])->toBe('Telecel Cash');

    Http::assertSentCount(2); // the second call to find() came from cache
});
