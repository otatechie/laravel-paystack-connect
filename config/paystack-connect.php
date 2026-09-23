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
    | Used when an amount is given without a currency. It must be one your
    | Paystack account can charge: each account charges in its own country's
    | currency (GHS, NGN, KES, ZAR, XOF, EGP or RWF), plus USD in some
    | countries if Paystack has enabled it for you. Anything else is refused
    | with "Currency not supported by merchant".
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
    | A fee is a percentage plus a flat amount, kept between min and max, and
    | never more than the payment. Amounts are in major units (GHS 5, not 500
    | pesewas). Per-currency rules override the default; a currency without
    | its own rule uses the default alone.
    |
    | Example with these settings: a GHS 50 payment gives 2.5% = GHS 1.25,
    | raised to the GHS 5 minimum, so the seller receives GHS 45.
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
            'GHS' => ['min' => 5, 'max' => 50],
            'NGN' => ['min' => 500, 'max' => 5000],
            'USD' => ['min' => 1, 'max' => 10],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who pays Paystack's transaction fee
    |--------------------------------------------------------------------------
    |
    | 'account': your platform pays it, out of your fee. With a GHS 5 fee and
    | Paystack charging GHS 0.98, you keep GHS 4.02 and the seller gets their
    | full share.
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
    | subaccount is created. In test mode, Paystack allows only 3 lookups of
    | real accounts a day.
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
    | Paystack tells your app about payments and refunds by calling this
    | route. In your Paystack dashboard (Settings > API Keys & Webhooks), put
    | https://your-app.com/{path} in the *Webhook URL* field, not the
    | Callback URL field; each checkout sends its own callback URL. Test and
    | live mode each have their own webhook URL.
    |
    | Paystack's servers must be able to reach the URL, so a local .test or
    | localhost address won't work. To receive webhooks on your machine, use
    | a tunnel such as `herd share`, `expose` or `ngrok`.
    |
    | Every request is checked against the x-paystack-signature header.
    | middleware is added in front of that check, for example a throttle.
    | Set enabled to false to register your own route instead.
    |
    | keep_days is how long processed events are kept. Schedule
    | `model:prune --model=Otatechie\PaystackConnect\Models\WebhookEvent` to
    | remove older ones; set it to null to keep them forever. Events that
    | failed are always kept, so `paystack-connect:retry-webhooks` can
    | process them again.
    |
    | allowed_ips adds a second check on where the request came from. To
    | turn it on, uncomment Paystack's published webhook IPs below. Behind a
    | proxy or load balancer (Cloudflare, AWS ELB, Forge with a balancer),
    | configure Laravel's trusted proxies first, or every webhook will look
    | like it came from the proxy and be rejected.
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
