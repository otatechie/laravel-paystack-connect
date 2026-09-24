<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paystack keys
    |--------------------------------------------------------------------------
    |
    | The secret key authenticates every API call and is what Paystack signs
    | webhooks with. Use your test keys (sk_test_..., pk_test_...) until you
    | go live. Test and live keys each have their own dashboard settings.
    |
    | The package never uses the public key. It's here for your own frontend,
    | if you open Paystack's inline popup with $payment->access_code.
    |
    */

    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    // Seconds to wait for each Paystack API call.
    'timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Used when an amount is given without a currency. Anything your Paystack
    | account can't charge is refused by Paystack.
    |
    */

    // Supported: GHS (Ghana), NGN (Nigeria), KES (Kenya), ZAR (South Africa),
    // XOF (Côte d'Ivoire), EGP (Egypt), RWF (Rwanda), and USD where enabled.
    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),

    /*
    |--------------------------------------------------------------------------
    | Platform fees
    |--------------------------------------------------------------------------
    |
    | Your cut of each payment that goes to a seller. A payment without a
    | seller has no fee: it's all yours anyway.
    |
    | The package works this out at every checkout; Paystack does the split.
    |
    | A fee is a percentage plus a flat amount, kept between min and max, and
    | never more than the payment. Amounts are in major units (GHS 5, not 500
    | pesewas). Per-currency rules override the default; a currency without
    | its own rule uses the default alone.
    |
    | Example: a GHS 10 payment gives you 2.5% = GHS 0.25; the seller gets
    | GHS 9.75.
    |
    | With bearer 'account' (below) you pay Paystack's fee out of yours, so
    | your fee must cover it or you lose money on each payment. These defaults
    | cover Paystack's published rates: Ghana 1.95%; Nigeria 1.5% + NGN 100
    | (over NGN 2,500, capped at NGN 2,000); Kenya up to 2.9% (cards); South
    | Africa 2.9% + R1 + VAT. Check your own rates at paystack.com/pricing.
    |
    | To override the fee for one payment, use ->fee('10.00') at checkout.
    |
    */

    'fees' => [
        'default' => [
            'percentage' => 2.5,
            'flat' => 0,
            'min' => null,
            'max' => null,
        ],

        'currencies' => [
            'NGN' => ['min' => 250, 'max' => 5000],
            'KES' => ['percentage' => 3],
            'ZAR' => ['percentage' => 3.5, 'flat' => 1.50],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who pays Paystack's transaction fee
    |--------------------------------------------------------------------------
    |
    | 'account': your platform pays it, out of your fee. On a GHS 200 payment,
    | your 2.5% is GHS 5, Paystack takes GHS 3.90, and you keep GHS 1.10; the
    | seller gets their full share.
    |
    | 'subaccount': the seller pays it, out of their share.
    |
    | To choose for one payment, use ->bearer('subaccount') at checkout.
    |
    */

    'bearer' => 'account',

    /*
    |--------------------------------------------------------------------------
    | Sellers
    |--------------------------------------------------------------------------
    |
    | verify_accounts asks Paystack for the account holder's name before a
    | subaccount is created, so money never settles to a mistyped account.
    | Paystack only offers this lookup in Ghana and Nigeria; in other
    | countries it's skipped and Paystack checks the account itself when the
    | subaccount is created.
    |
    | percentage_charge is the share Paystack keeps for you when a payment
    | has no fee of its own. Checkouts from this package always send their
    | fee, so this only affects payments made outside it, such as Paystack
    | payment pages or links for the seller.
    |
    */

    'sellers' => [
        'verify_accounts' => true,
        'percentage_charge' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Put https://your-app.com/{path} in the *Webhook URL* field of your
    | Paystack dashboard (not Callback URL), in both test and live mode.
    | Every request's signature is checked; middleware runs before that.
    | Set enabled to false to register your own route instead.
    |
    | keep_days: how long processed events are kept (null keeps them forever).
    | It only takes effect if you schedule
    | `model:prune --model=Otatechie\PaystackConnect\Models\WebhookEvent`.
    | Failed events are always kept, for `paystack-connect:retry-webhooks`.
    |
    | allowed_ips: uncomment to accept only Paystack's servers. Behind a proxy
    | or load balancer, set up Laravel's trusted proxies first, or every
    | webhook will be rejected.
    |
    */

    'webhook' => [
        'enabled' => true,
        'path' => 'paystack/webhook',
        'middleware' => [],
        'keep_days' => 30,
        'allowed_ips' => [
            // '52.31.139.75',
            // '52.49.173.169',
            // '52.214.14.220',
        ],
    ],

    // Seconds to cache Paystack's list of banks and mobile money networks.
    'banks_cache_ttl' => 60 * 60 * 24,

    // Where webhook problems are logged. Leave empty for your default channel.
    'log_channel' => env('PAYSTACK_LOG_CHANNEL'),

];
