<?php

namespace Otatechie\PaystackConnect\Facades;

use Illuminate\Support\Facades\Facade;
use Otatechie\PaystackConnect\Banks;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\PaymentReconciler;
use Otatechie\PaystackConnect\Subaccounts;
use Otatechie\PaystackConnect\Testing\PaystackFake;

/**
 * @method static \Otatechie\PaystackConnect\Checkout checkout()
 * @method static \Otatechie\PaystackConnect\Banks banks()
 * @method static \Otatechie\PaystackConnect\Subaccounts subaccounts()
 * @method static \Otatechie\PaystackConnect\Fees fees()
 * @method static \Otatechie\PaystackConnect\Support\Money feeFor(\Otatechie\PaystackConnect\Support\Money $amount)
 * @method static \Otatechie\PaystackConnect\Models\Payment|null verify(string $reference)
 * @method static array<string, mixed> refund(\Otatechie\PaystackConnect\Models\Payment $payment, ?\Otatechie\PaystackConnect\Support\Money $amount = null)
 * @method static \Otatechie\PaystackConnect\Http\PaystackClient client()
 *
 * @see \Otatechie\PaystackConnect\PaystackConnect
 */
class PaystackConnect extends Facade
{
    /** Replace Paystack with a fake for the rest of the test. */
    public static function fake(): PaystackFake
    {
        $app = static::getFacadeApplication();

        $fake = new PaystackFake($app->make(PaymentReconciler::class));

        $app->instance(PaystackClient::class, $fake);

        // These hold the client they were built with; rebuild them with the fake.
        $app->forgetInstance(Banks::class);
        $app->forgetInstance(Subaccounts::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Otatechie\PaystackConnect\PaystackConnect::class;
    }
}
