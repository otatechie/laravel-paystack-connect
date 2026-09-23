# Laravel Paystack Connect

Marketplace payments for Laravel on Paystack. Your customers pay a seller, the
seller's share settles straight to their bank or mobile money wallet, and your
platform keeps a fee.

Paystack's API gives you subaccounts and split payments. This package gives
you everything around them that you would otherwise build by hand: seller
onboarding, fee rules, local records, and webhooks you can trust.

- **Exact money.** Amounts are integers in pesewas, kobo or cents. `19.99` is
  always `1999`, never `1998`.
- **Seller onboarding.** Bank and mobile money lists come live from Paystack,
  the account holder's name is verified before any subaccount is created
  (in Ghana and Nigeria, where Paystack offers it), and
  connecting the same seller twice updates their subaccount instead of
  creating a duplicate.
- **Platform fees.** A percentage plus a flat amount, with a minimum and a
  maximum per currency, never more than the payment itself.
- **Webhooks that are safe to trust.** Signatures are checked against the raw
  body, every event is stored once so Paystack's retries are ignored, and a
  payment is only marked paid when the amount and currency match exactly.
- **Nothing fails silently.** Every Paystack error throws with Paystack's own
  message, and webhook failures are logged and retried.

## Requirements

PHP 8.3+ and Laravel 12 or 13.

## Installation

```bash
composer require otatechie/laravel-paystack-connect
php artisan vendor:publish --tag="paystack-connect-migrations"
php artisan migrate
php artisan vendor:publish --tag="paystack-connect-config"
```

Add your keys to `.env`:

```env
PAYSTACK_SECRET_KEY=sk_test_xxx
PAYSTACK_PUBLIC_KEY=pk_test_xxx
PAYSTACK_CURRENCY=GHS
```

In your Paystack dashboard, under Settings → API Keys & Webhooks, put
`https://your-app.com/paystack/webhook` in the **Webhook URL** field. Not the
Callback URL field: each checkout sends its own. Test and live mode each have
their own webhook URL. The path can be changed in `config/paystack-connect.php`.

## Onboard a seller

Add the trait to the model that gets paid, such as a business, vendor or school:

```php
use Otatechie\PaystackConnect\Concerns\HasPaystackSubaccount;

class Business extends Model
{
    use HasPaystackSubaccount;
}
```

Show the seller Paystack's list of banks or mobile money networks, and keep
the code they pick:

```php
use Otatechie\PaystackConnect\Facades\PaystackConnect;

PaystackConnect::banks()->list('ghana');          // banks
PaystackConnect::banks()->mobileMoney('ghana');   // MTN, Telecel, AirtelTigo
```

Countries use Paystack's names: `ghana`, `nigeria`, `kenya`, `south africa`,
`côte d'ivoire`, `egypt` and `rwanda`.

Then connect their account:

```php
use Otatechie\PaystackConnect\Support\SettlementAccount;

$business->connectPaystackAccount(
    SettlementAccount::mobileMoney('Kofi Prints', 'MTN', '0241234567', 'GHS')
        ->withContact(email: $owner->email, name: $owner->name),
);

$business->canReceivePaystackPayments(); // true
```

In Ghana and Nigeria, the account holder's name is checked with Paystack
first. If it can't be resolved, a `PaystackException` explains why and nothing
is created. Paystack has no such lookup in other countries, so there it checks
the account itself when the subaccount is created. To skip the lookup, set
`sellers.verify_accounts` to `false`.

## Take a payment

```php
$payment = PaystackConnect::checkout()
    ->amount('250.00', 'GHS')
    ->email($client->email)
    ->seller($business)            // settles to the seller, minus your fee
    ->for($invoice)                // optional: what is being paid for
    ->callbackUrl(route('invoices.paid', $invoice))
    ->create();

return redirect($payment->authorization_url);
```

To use Paystack's popup instead of a redirect, pass `$payment->access_code`
to Paystack Inline.

With Inertia, a plain `redirect()` fails with a CORS error, because the
browser won't follow an XHR redirect to another site. Use a full page visit
instead:

```php
return Inertia::location($payment->authorization_url);
```

The fee comes from your config. To override it for one payment, use
`->fee('10.00')`. To choose who pays Paystack's own fee, use
`->bearer('subaccount')`.

On your callback page, confirm the payment straight away:

```php
$payment = PaystackConnect::verify($request->query('reference'));

$payment->isSuccessful();
```

The webhook is still the source of truth. Verifying and the webhook both
update the payment, and it only ever settles once.

## React to payments

```php
use Otatechie\PaystackConnect\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $invoice = $event->payment->payable;

    $invoice->markPaid();
    Mail::to($invoice->client)->queue(new ReceiptMail($invoice));
});
```

| Event | When |
|---|---|
| `PaymentSucceeded` | Paystack confirmed the charge and the amount and currency match. |
| `PaymentFailed` | The charge was declined. The customer can still pay on the same checkout, so `PaymentSucceeded` may follow. |
| `PaymentAmountMismatch` | Paystack charged a different amount or currency. The payment is not marked paid; review it. |
| `PaymentRefunded` | Paystack processed a refund. `$event->amount` is how much went back. |
| `SubaccountConnected` | A seller's subaccount was created or updated. |
| `WebhookReceived` | Any verified webhook, including events this package doesn't handle itself. |

A checkout the customer hasn't paid yet stays `pending`, even though Paystack
reports it as "abandoned": they can still come back and pay. To clean up old
unpaid checkouts, query pending payments older than you care about.

Listeners run once per payment, even when Paystack retries a webhook. If a
listener throws, the webhook returns an error, the event is kept, and
Paystack's next retry processes it again. In live mode Paystack retries every
3 minutes for the first 4 tries, then hourly for 72 hours; in test mode,
hourly for 10 hours. You can also resend events from the Paystack dashboard.

Paystack gives each delivery 30 seconds, so keep listeners quick and queue
slow work such as emails, as above.

## Refunds

```php
PaystackConnect::refund($payment);                                  // everything
PaystackConnect::refund($payment, Money::major('50.00', 'GHS'));    // part of it
```

Paystack processes refunds in the background, which can take a while. Until
it does, the amount is held as pending, so the same money can't be refunded
twice. When the `refund.processed` webhook arrives, the payment's
`refunded_amount` goes up and `PaymentRefunded` is dispatched. Once the whole
amount is back, the status becomes `refunded`. If Paystack fails the refund
(`refund.failed`), the amount can be refunded again. If Paystack needs the
customer's bank details first (`refund.needs-attention`), the refund stays
pending until you provide them through Paystack's retry endpoint or dashboard.

```php
$payment->pendingRefundAmount(); // GHS 50.00 until Paystack processes it
$payment->refundedAmount();      // GHS 50.00 after
$payment->refundableAmount();    // GHS 200.00: not refunded and not pending
$payment->isRefunded();          // false until everything is back
```

## Fees

```php
'fees' => [
    'default' => ['percentage' => 2.5, 'flat' => 0, 'min' => null, 'max' => null],
    'currencies' => [
        'GHS' => ['min' => 5, 'max' => 50],
    ],
],
```

Amounts are in major units (GHS 5, not 500 pesewas). A currency without its
own rule uses the default alone. Payments without a seller have no fee. To
preview a fee:

```php
PaystackConnect::feeFor(Money::major('100.00', 'GHS')); // GHS 5.00
```

By default your platform pays Paystack's own fee, out of your fee (`bearer`
set to `account`). Set it to `subaccount` to have sellers pay it instead.

## Currencies

Each Paystack account charges in its own country's currency, plus USD in some
countries if Paystack has enabled it for you. Anything else is refused with
"Currency not supported by merchant".

| Country | Currency | Paystack's minimum | Account holder lookup |
|---|---|---|---|
| Ghana | GHS | GHS 0.10 | yes |
| Nigeria | NGN | NGN 50.00 | yes |
| Kenya | KES | KES 3.00 | no |
| South Africa | ZAR | ZAR 1.00 | no |
| Côte d'Ivoire | XOF | XOF 1 | no |
| Egypt | EGP | not published | no |
| Rwanda | RWF | not published | no |

USD has a minimum of USD 2.00; Paystack documents it for Kenya and Nigeria.
XOF and RWF have no subunit, so amounts must
be whole: `Money::major('10.50', 'XOF')` throws instead of Paystack silently
charging XOF 10. Fees in these currencies are rounded to whole units.

## Moving an existing app over

If your sellers already have subaccounts, copy them into the local table:

```bash
php artisan paystack-connect:import-subaccounts
```

Subaccounts created by this package carry their owner in Paystack's metadata,
so they are linked to the right model. To see bank and network codes:

```bash
php artisan paystack-connect:banks ghana --type=mobile_money
```

## Testing your app

`PaystackConnect::fake()` replaces Paystack for the rest of the test. Checkouts,
subaccounts, bank lists, account checks and refunds all get realistic
responses, and nothing leaves your machine.

```php
use Otatechie\PaystackConnect\Facades\PaystackConnect;

it('marks the invoice paid', function () {
    $paystack = PaystackConnect::fake();

    $this->post(route('invoices.pay', $invoice))->assertRedirect();

    $paystack->assertCheckoutCreated(fn ($data) => $data['amount'] === 25000);

    $paystack->pay(Payment::first());   // as if charge.success arrived; your listeners run

    expect($invoice->refresh()->paid)->toBeTrue();
});
```

| Method | What it does |
|---|---|
| `pay($payment)` | Settles the payment as paid. `verify()` reports it as paid from then on; before that, it reports "abandoned" like Paystack does, and the payment stays pending. |
| `fail($payment, $reason)` | Settles the payment as failed. |
| `refunded($payment, ?Money)` | Records a refund, as the `refund.processed` webhook would. |
| `assertCheckoutCreated(?callable)` | A checkout was started. The callback receives what was sent to Paystack. |
| `assertSubaccountCreated(?callable)` | A seller's subaccount was created. |
| `assertNothingSent()` | Nothing was sent to Paystack. |

The fake throws on any other endpoint. For those, use `Http::fake()`.

## Trying it against Paystack's test mode

- Pay with Paystack's test card: `4084 0840 8408 4081`, CVV `408`, any future
  expiry, PIN `0000`, OTP `123456`.
- Connecting a seller needs a real account or wallet number, even in test
  mode. No money moves. Paystack allows only 3 lookups of real accounts a day
  in test mode. Its test bank code `001` works for lookups but not for
  creating a subaccount.
- Webhooks need a public URL. A `.test` or `localhost` address won't work, so
  use a tunnel such as `herd share`, `expose` or `ngrok`, and put
  `https://<tunnel>/paystack/webhook` in the test Webhook URL field.
- Test refunds can stay pending for a while before Paystack processes them.

## Security

Every webhook's signature is checked. To also accept webhooks only from
Paystack's servers, uncomment their IP addresses under `webhook.allowed_ips`
in the config. If your app sits behind a proxy or load balancer, set up
Laravel's trusted proxies first, or every webhook will be rejected.

## Contributing

```bash
composer test
composer analyse
composer format
```

## License

MIT
