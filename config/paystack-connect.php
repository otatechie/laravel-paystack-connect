<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paystack keys
    |--------------------------------------------------------------------------
    |
    | The secret key signs every API call and every webhook Paystack sends
    | you. Use your test key (sk_test_...) until you go live.
    |
    */

    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    'timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Used when an amount is given without a currency.
    |
    */

    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),

    /*
    |--------------------------------------------------------------------------
    | Platform fees
    |--------------------------------------------------------------------------
    |
    | Your cut of each payment that goes to a seller, in major units (GHS 5,
    | not 500 pesewas). A fee is a percentage plus a flat amount, kept between
    | min and max. Per-currency rules override the default.
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
    | 'account' means your platform pays it; 'subaccount' means the seller does.
    |
    */

    'bearer' => 'account',

    /*
    |--------------------------------------------------------------------------
    | Sellers
    |--------------------------------------------------------------------------
    |
    | verify_accounts resolves the account holder's name with Paystack before
    | a subaccount is created, so money never settles to a mistyped account.
    | percentage_charge is the subaccount's default share for you when a
    | payment has no explicit fee; checkout always sends an explicit fee.
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
    | Point Paystack's webhook URL at https://your-app.com/{path}. Every
    | request is checked against the x-paystack-signature header.
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
        'allowed_ips' => [
            // '52.31.139.75',
            // '52.49.173.169',
            // '52.214.14.220',
        ],
    ],

    'banks_cache_ttl' => 60 * 60 * 24,

    'log_channel' => env('PAYSTACK_LOG_CHANNEL'),

];
