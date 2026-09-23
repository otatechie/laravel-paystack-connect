<?php

namespace Otatechie\PaystackConnect\Commands;

use Illuminate\Console\Command;
use Otatechie\PaystackConnect\Facades\PaystackConnect;

class ListBanksCommand extends Command
{
    protected $signature = 'paystack-connect:banks
        {country=ghana : Paystack country name: ghana, nigeria, kenya, "south africa", "côte d\'ivoire", egypt or rwanda}
        {--type= : Only this type, such as mobile_money}';

    protected $description = 'List the banks and mobile money networks Paystack supports, with their codes';

    public function handle(): int
    {
        $banks = PaystackConnect::banks()->list($this->argument('country'), $this->option('type') ?: null);

        if ($banks->isEmpty()) {
            $this->warn('Paystack returned no banks for that country and type.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Code', 'Type', 'Currency'], $banks->map(fn ($bank) => [
            $bank['name'], $bank['code'], $bank['type'] ?? '', $bank['currency'] ?? '',
        ]));

        return self::SUCCESS;
    }
}
