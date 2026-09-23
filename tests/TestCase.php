<?php

namespace Otatechie\PaystackConnect\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Otatechie\PaystackConnect\PaystackConnectServiceProvider;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'sk_test_secret';

    protected function getPackageProviders($app): array
    {
        return [PaystackConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('paystack-connect.secret_key', self::SECRET);
    }

    protected function defineDatabaseMigrations(): void
    {
        (include __DIR__.'/../database/migrations/create_paystack_connect_tables.php.stub')->up();

        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    /** Send a webhook exactly as Paystack would: the signature covers the raw body. */
    protected function postWebhook(string $rawBody, ?string $signature = null): TestResponse
    {
        return $this->call(
            'POST',
            '/paystack/webhook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $rawBody, self::SECRET),
            ],
            content: $rawBody,
        );
    }
}
