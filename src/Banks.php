<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Http\PaystackClient;

/**
 * Banks and mobile money networks, always taken from Paystack's own list.
 *
 * Codes are never hardcoded or guessed from names, so a rebrand (Vodafone Cash
 * becoming Telecel Cash, say) can't silently break settlement.
 */
class Banks
{
    /** Paystack's country name for each local currency. */
    public const COUNTRIES = [
        'GHS' => 'ghana',
        'NGN' => 'nigeria',
        'KES' => 'kenya',
        'ZAR' => 'south africa',
        'XOF' => "côte d'ivoire",
        'EGP' => 'egypt',
        'RWF' => 'rwanda',
    ];

    public function __construct(
        private readonly PaystackClient $client,
        private readonly Cache $cache,
        private readonly int $ttl,
    ) {}

    /**
     * @param  string  $country  Paystack's country name: "ghana", "nigeria", "kenya", "south africa",
     *                           "côte d'ivoire", "egypt" or "rwanda".
     * @param  string|null  $type  Optional, such as "mobile_money", "ghipss" or "nuban".
     * @return Collection<int, array{id: int|null, name: string, code: string, type: string|null, currency: string|null}>
     */
    public function list(string $country, ?string $type = null): Collection
    {
        $country = strtolower($country);

        $banks = $this->cache->remember(
            "paystack-connect:banks:{$country}:".($type ?? 'all'),
            $this->ttl,
            fn () => $this->fetchAll(array_filter(['country' => $country, 'type' => $type])),
        );

        return collect($banks);
    }

    /** @return Collection<int, array{id: int|null, name: string, code: string, type: string|null, currency: string|null}> */
    public function mobileMoney(string $country): Collection
    {
        return $this->list($country, 'mobile_money');
    }

    /** @return array{id: int|null, name: string, code: string, type: string|null, currency: string|null}|null */
    public function find(string $country, string $code): ?array
    {
        return $this->list($country)->firstWhere('code', $code);
    }

    /**
     * Find a bank by Paystack's numeric id, which is how subaccounts refer to it.
     *
     * @return array{id: int|null, name: string, code: string, type: string|null, currency: string|null}|null
     */
    public function findById(string $currency, int $id): ?array
    {
        $country = self::COUNTRIES[strtoupper($currency)] ?? null;

        return $country ? $this->list($country)->firstWhere('id', $id) : null;
    }

    /**
     * Ask Paystack who owns an account.
     *
     * @return string The account holder's name.
     *
     * @throws PaystackException When Paystack can't resolve the account.
     */
    public function resolve(string $accountNumber, string $bankCode): string
    {
        $response = $this->client->get('/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        return (string) $response['data']['account_name'];
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array{id: int|null, name: string, code: string, type: string|null, currency: string|null}>
     */
    private function fetchAll(array $query): array
    {
        $banks = [];
        $cursor = null;

        // Paystack pages this list; follow the cursor so nothing is cut off.
        do {
            $response = $this->client->get('/bank', array_filter([
                ...$query,
                'perPage' => 100,
                'use_cursor' => 'true',
                'next' => $cursor,
            ]));

            foreach ($response['data'] ?? [] as $bank) {
                $banks[] = [
                    'id' => isset($bank['id']) ? (int) $bank['id'] : null,
                    'name' => $bank['name'],
                    'code' => $bank['code'],
                    'type' => $bank['type'] ?? null,
                    'currency' => $bank['currency'] ?? null,
                ];
            }

            $cursor = $response['meta']['next'] ?? null;
        } while ($cursor);

        return $banks;
    }
}
