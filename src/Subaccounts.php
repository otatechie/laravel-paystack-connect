<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Otatechie\PaystackConnect\Events\SubaccountConnected;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Support\SettlementAccount;

/**
 * Creates and updates sellers' Paystack subaccounts.
 *
 * The link between a seller and their subaccount lives in your database, so
 * connecting the same seller twice updates their subaccount instead of
 * creating a duplicate.
 */
class Subaccounts
{
    /**
     * Paystack can only look up account holders in Ghana and Nigeria. Elsewhere
     * it checks the account itself when the subaccount is created.
     */
    private const LOOKUP_CURRENCIES = ['GHS', 'NGN'];

    public function __construct(
        private readonly PaystackClient $client,
        private readonly Banks $banks,
        private readonly Cache $cache,
        private readonly bool $verifyAccounts,
        private readonly float $defaultPercentageCharge,
    ) {}

    public function connect(Model $owner, SettlementAccount $account, ?float $percentageCharge = null): Subaccount
    {
        // Two overlapping connects for a new seller would create two Paystack subaccounts.
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider
            ? $store->lock('paystack-connect:connect:'.$owner->getMorphClass().':'.$owner->getKey(), 30)
            : null;

        return $lock ? $lock->block(10, fn () => $this->doConnect($owner, $account, $percentageCharge)) : $this->doConnect($owner, $account, $percentageCharge);
    }

    private function doConnect(Model $owner, SettlementAccount $account, ?float $percentageCharge): Subaccount
    {
        if ($this->verifyAccounts && $account->accountName === null && in_array($account->currency, self::LOOKUP_CURRENCIES, true)) {
            $account = $account->withAccountName($this->banks->resolve($account->accountNumber, $account->bankCode));
        }

        $existing = $this->for($owner);

        $payload = array_filter([
            'business_name' => $account->businessName,
            'settlement_bank' => $account->bankCode,
            'account_number' => $account->accountNumber,
            'percentage_charge' => $percentageCharge ?? $this->defaultPercentageCharge,
            'primary_contact_email' => $account->contactEmail,
            'primary_contact_name' => $account->contactName,
            'primary_contact_phone' => $account->contactPhone,
            'metadata' => json_encode([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ]),
        ], fn ($value) => $value !== null);

        $response = $existing
            ? $this->client->put("/subaccount/{$existing->subaccount_code}", $payload)
            : $this->client->post('/subaccount', $payload);

        $data = $response['data'];

        $subaccount = Subaccount::updateOrCreate(
            ['subaccount_code' => $data['subaccount_code'] ?? $existing?->subaccount_code],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'business_name' => $account->businessName,
                'settlement_bank' => $account->bankCode,
                'bank_name' => $account->bankName ?? ($data['settlement_bank'] ?? null),
                'account_type' => $account->accountType,
                'account_number' => $account->accountNumber,
                'account_number_last4' => substr($account->accountNumber, -4),
                'account_name' => $account->accountName ?? ($data['account_name'] ?? null),
                'currency' => $account->currency,
                'percentage_charge' => $payload['percentage_charge'],
                'active' => (bool) ($data['active'] ?? true),
                'paystack_data' => $this->withoutSecrets($data),
            ],
        );

        SubaccountConnected::dispatch($subaccount);

        return $subaccount;
    }

    public function for(Model $owner): ?Subaccount
    {
        return Subaccount::query()->for($owner)->first();
    }

    /**
     * Link an imported subaccount to its seller. The owner is also recorded in
     * Paystack's metadata, so a later import keeps the link.
     *
     * @throws InvalidArgumentException When no imported subaccount has this code.
     */
    public function attach(Model $owner, string $subaccountCode): Subaccount
    {
        $subaccount = Subaccount::query()->where('subaccount_code', $subaccountCode)->first()
            ?? throw new InvalidArgumentException("No subaccount {$subaccountCode} is stored locally. Run paystack-connect:import-subaccounts first.");

        $this->client->put("/subaccount/{$subaccountCode}", [
            'metadata' => json_encode([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ]),
        ]);

        $subaccount->update([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);

        return $subaccount;
    }

    /**
     * Copy every subaccount on your Paystack account into the local table.
     * Useful when adopting this package in an app that already has sellers.
     *
     * Subaccounts whose metadata names an owner (owner_type and owner_id)
     * are linked to that model.
     *
     * @return int The number of subaccounts imported or updated.
     */
    public function import(): int
    {
        $count = 0;
        $page = 1;

        do {
            $response = $this->client->get('/subaccount', ['perPage' => 100, 'page' => $page]);

            foreach ($response['data'] ?? [] as $data) {
                $this->importOne($data);
                $count++;
            }

            $pages = (int) ($response['meta']['pageCount'] ?? 1);
            $page++;
        } while ($page <= $pages);

        return $count;
    }

    /** @param array<string, mixed> $data */
    private function importOne(array $data): void
    {
        $metadata = is_string($data['metadata'] ?? null) ? json_decode($data['metadata'], true) : ($data['metadata'] ?? []);
        $owner = null;

        if (isset($metadata['owner_type'], $metadata['owner_id'])) {
            $class = Relation::getMorphedModel($metadata['owner_type']) ?? $metadata['owner_type'];
            $owner = is_string($class) && is_subclass_of($class, Model::class) ? $class::find($metadata['owner_id']) : null;
        }

        $accountNumber = (string) ($data['account_number'] ?? '');
        $currency = strtoupper($data['currency'] ?? (string) config('paystack-connect.currency', 'GHS'));

        // Paystack lists the bank's name as settlement_bank; the code comes from bank_id.
        $bankId = $data['bank_id'] ?? $data['bank'] ?? null;
        $bank = is_numeric($bankId) ? $this->banks->findById($currency, (int) $bankId) : null;

        // A subaccount linked locally with attach() keeps its owner when Paystack's metadata has none.
        $ownerColumns = $owner ? ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()] : [];

        Subaccount::updateOrCreate(
            ['subaccount_code' => $data['subaccount_code']],
            [
                ...$ownerColumns,
                'business_name' => $data['business_name'] ?? '',
                'settlement_bank' => $bank['code'] ?? null,
                'bank_name' => $bank['name'] ?? $data['settlement_bank'] ?? null,
                'account_number' => $accountNumber,
                'account_number_last4' => substr($accountNumber, -4),
                'account_name' => $data['account_name'] ?? null,
                'currency' => $currency,
                'percentage_charge' => $data['percentage_charge'] ?? 0,
                'active' => (bool) ($data['active'] ?? true),
                'paystack_data' => $this->withoutSecrets($data),
            ],
        );
    }

    /**
     * Paystack echoes the account number and contact details back in clear
     * text. They're stored encrypted in their own columns, never here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $data): array
    {
        unset($data['account_number'], $data['primary_contact_email'], $data['primary_contact_name'], $data['primary_contact_phone']);

        return $data;
    }
}
