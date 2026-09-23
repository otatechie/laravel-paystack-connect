<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    public function __construct(
        private readonly PaystackClient $client,
        private readonly Banks $banks,
        private readonly bool $verifyAccounts,
        private readonly float $defaultPercentageCharge,
    ) {}

    public function connect(Model $owner, SettlementAccount $account, ?float $percentageCharge = null): Subaccount
    {
        if ($this->verifyAccounts && $account->accountName === null) {
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
                'paystack_data' => $data,
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
            $owner = class_exists($class) ? $class::find($metadata['owner_id']) : null;
        }

        $accountNumber = (string) ($data['account_number'] ?? '');

        Subaccount::updateOrCreate(
            ['subaccount_code' => $data['subaccount_code']],
            [
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getKey(),
                'business_name' => $data['business_name'] ?? '',
                'settlement_bank' => (string) ($data['settlement_bank'] ?? ''),
                'bank_name' => $data['settlement_bank'] ?? null,
                'account_number' => $accountNumber,
                'account_number_last4' => substr($accountNumber, -4),
                'account_name' => $data['account_name'] ?? null,
                'currency' => $data['currency'] ?? (string) config('paystack-connect.currency', 'GHS'),
                'percentage_charge' => $data['percentage_charge'] ?? 0,
                'active' => (bool) ($data['active'] ?? true),
                'paystack_data' => $data,
            ],
        );
    }
}
