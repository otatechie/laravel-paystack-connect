<?php

namespace Otatechie\PaystackConnect\Commands;

use Illuminate\Console\Command;
use Otatechie\PaystackConnect\Facades\PaystackConnect;

class ImportSubaccountsCommand extends Command
{
    protected $signature = 'paystack-connect:import-subaccounts';

    protected $description = 'Copy every subaccount on your Paystack account into the local table';

    public function handle(): int
    {
        $count = PaystackConnect::subaccounts()->import();

        $this->info("Imported {$count} subaccount(s).");

        return self::SUCCESS;
    }
}
