<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Route;
use Otatechie\PaystackConnect\Commands\ImportSubaccountsCommand;
use Otatechie\PaystackConnect\Commands\ListBanksCommand;
use Otatechie\PaystackConnect\Http\Controllers\WebhookController;
use Otatechie\PaystackConnect\Http\Middleware\VerifyPaystackSignature;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PaystackConnectServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-paystack-connect')
            ->hasConfigFile('paystack-connect')
            ->hasMigration('create_paystack_connect_tables')
            ->hasCommands([
                ListBanksCommand::class,
                ImportSubaccountsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaystackClient::class, fn () => new PaystackClient(
            config('paystack-connect.secret_key'),
            (string) config('paystack-connect.base_url', 'https://api.paystack.co'),
            (int) config('paystack-connect.timeout', 15),
        ));

        $this->app->singleton(Banks::class, fn ($app) => new Banks(
            $app->make(PaystackClient::class),
            $app->make(Cache::class),
            (int) config('paystack-connect.banks_cache_ttl', 86400),
        ));

        $this->app->singleton(Subaccounts::class, fn ($app) => new Subaccounts(
            $app->make(PaystackClient::class),
            $app->make(Banks::class),
            (bool) config('paystack-connect.sellers.verify_accounts', true),
            (float) config('paystack-connect.sellers.percentage_charge', 0),
        ));

        $this->app->singleton(Fees::class, fn () => new Fees((array) config('paystack-connect.fees', [])));

        // A fresh builder per checkout.
        $this->app->bind(Checkout::class, fn ($app) => new Checkout(
            $app->make(PaystackClient::class),
            $app->make(Subaccounts::class),
            $app->make(Fees::class),
            (string) config('paystack-connect.bearer', 'account'),
        ));

        $this->app->singleton(PaystackConnect::class, fn ($app) => new PaystackConnect($app));
    }

    public function packageBooted(): void
    {
        if (! config('paystack-connect.webhook.enabled', true)) {
            return;
        }

        // Registered outside the "web" group: webhooks carry no CSRF token or session.
        Route::post((string) config('paystack-connect.webhook.path', 'paystack/webhook'), WebhookController::class)
            ->middleware([...(array) config('paystack-connect.webhook.middleware', []), VerifyPaystackSignature::class])
            ->name('paystack-connect.webhook');
    }
}
