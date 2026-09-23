# Laravel Paystack Connect

[![Tests](https://github.com/otatechie/laravel-paystack-connect/actions/workflows/tests.yml/badge.svg)](https://github.com/otatechie/laravel-paystack-connect/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/otatechie/laravel-paystack-connect?include_prereleases)](https://packagist.org/packages/otatechie/laravel-paystack-connect)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

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

> **Beta.** The package is in beta while it's proven in a production app. The
> API and the migration may still change before `v1.0.0`.

**What it doesn't do:** each payment goes to one seller, so a cart with
several sellers needs one payment per seller (or Paystack's
[multi-split payments](https://paystack.com/docs/payments/multi-split-payments/), which this package
doesn't wrap yet). Payouts to sellers happen
through Paystack's settlements, not this package, and disputes are only
surfaced as raw `WebhookReceived` events. For anything else Paystack offers,
`PaystackConnect::client()` gives you an authenticated client for its API.

**Contents:** [Installation](#installation) ·
[Onboard a seller](#onboard-a-seller) · [Take a payment](#take-a-payment) ·
[React to payments](#react-to-payments) · [Refunds](#refunds) · [Fees](#fees) ·
[Currencies](#currencies) · [Moving an existing app over](#moving-an-existing-app-over) ·
[Testing your app](#testing-your-app) ·
[Trying it against Paystack's test mode](#trying-it-against-paystacks-test-mode) ·
[Security](#security)

## Requirements

PHP 8.3+ and Laravel 12 or 13. PHP 8.5 is supported on Laravel 13.

## Installation

Install it while it's in beta, then publish the migration and config:

```bash
composer require otatechie/laravel-paystack-connect:^1.0@beta
php artisan vendor:publish --tag="paystack-connect-migrations"
php artisan migrate
php artisan vendor:publish --tag="paystack-connect-config"
```

Add your keys to `.env`:

```env
PAYSTACK_SECRET_KEY=sk_test_xxx
PAYSTACK_PUBLIC_KEY=pk_test_xxx

# Your Paystack account's currency: GHS, NGN, KES, ZAR, XOF, EGP or RWF
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
first ([Paystack only offers this lookup there](https://paystack.com/docs/identity-verification/verify-account-number/)). If it can't be resolved, a `PaystackException` explains why and nothing
is created. Paystack has no such lookup in other countries, so there it checks
the account itself when the subaccount is created. To skip the lookup, set
`sellers.verify_accounts` to `false`.

### An onboarding page

Paystack has no hosted onboarding, so sellers connect their account on a page
in your app. The package ships no views; here's a minimal one to copy and
adapt, whether you use Blade, Inertia or Livewire.

```php
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Otatechie\PaystackConnect\Banks;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Facades\PaystackConnect;
use Otatechie\PaystackConnect\Support\SettlementAccount;

class PayoutAccountController
{
    // Your Paystack account's currency, and Paystack's name for its country:
    // GHS → "ghana", NGN → "nigeria", KES → "kenya", and so on.
    private function currency(): string
    {
        return strtoupper(config('paystack-connect.currency'));
    }

    private function country(): string
    {
        return Banks::COUNTRIES[$this->currency()];
    }

    public function edit()
    {
        return view('payout-account', [
            'banks' => PaystackConnect::banks()->list($this->country())->sortBy('name'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string'],
            'account_number' => ['required', 'string'],
        ]);

        $bank = PaystackConnect::banks()->find($this->country(), $data['bank_code'])
            ?? throw ValidationException::withMessages(['bank_code' => 'Pick a bank or network from the list.']);

        $business = $request->user()->business;

        $account = $bank['type'] === 'mobile_money'
            ? SettlementAccount::mobileMoney($business->name, $bank['code'], $data['account_number'], $this->currency(), $bank['name'])
            : SettlementAccount::bank($business->name, $bank['code'], $data['account_number'], $this->currency(), $bank['name']);

        try {
            $business->connectPaystackAccount($account->withContact(email: $request->user()->email));
        } catch (PaystackException $e) {
            // For example "Could not resolve account name" for a mistyped number.
            return back()->withInput()->withErrors(['account_number' => $e->getMessage()]);
        }

        return back()->with('status', 'Payout account connected.');
    }
}
```

```blade
<form method="POST" action="{{ route('payout-account.update') }}">
    @csrf
    @method('PUT')

    <select name="bank_code" required>
        @foreach ($banks as $bank)
            <option value="{{ $bank['code'] }}">{{ $bank['name'] }}</option>
        @endforeach
    </select>

    <input name="account_number" placeholder="Account or mobile money number" required>
    @error('account_number') <p>{{ $message }}</p> @enderror

    <button>Connect payout account</button>
</form>
```

If your sellers are in several countries, let them pick a country first and
use it in place of `country()`.

Submitting the form again updates the same subaccount.

### The subaccount record

`$business->paystackSubaccount` is a `Subaccount` model, stored in
`paystack_subaccounts`.

| Field or method | Meaning |
|---|---|
| `subaccount_code` | Paystack's code for the subaccount, `ACCT_...`. |
| `business_name`, `bank_name`, `account_name` | What was connected, and the holder's name where Paystack looks it up. |
| `maskedAccountNumber()` | `"•••• 4567"`, for display. The full number is encrypted at rest and never included in JSON. |
| `active` | Whether Paystack will settle to it. `canReceivePaystackPayments()` checks this. |
| `owner`, `payments()` | The seller model, and every payment made to them. |

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

The fee comes from your config and is sent to Paystack as the transaction's
`transaction_charge`, a flat amount that goes to your account whatever the
subaccount's percentage says ([split payments](https://paystack.com/docs/payments/split-payments/)).
To override it for one payment, use `->fee('10.00')`. To choose who pays
Paystack's own fee, use `->bearer('subaccount')`.

Other options:

```php
->channels(['card', 'mobile_money'])   // limit how the customer can pay
->metadata(['order_id' => $order->id]) // sent to Paystack, shown in its dashboard
->reference('INV-2026-0042')           // your own reference; must be unique
```

Channels Paystack accepts: `card`, `bank`, `apple_pay`, `ussd`, `qr`,
`mobile_money`, `bank_transfer`, `eft`, `capitec_pay` and `payattitude`; which
ones the customer sees depends on their country. A reference may contain only
letters, digits, `-`, `.`, `=` and `_` ([Transaction API](https://paystack.com/docs/api/transaction/);
`_` isn't listed there, but Paystack accepts it).

Without `->seller()`, the whole amount goes to your own Paystack balance and
no fee is taken.

### The payment record

`create()` returns a `Payment` model, stored in `paystack_payments`. Amounts
are in minor units; the helpers give you `Money` objects.

| Field or method | Meaning |
|---|---|
| `reference` | Sent to Paystack. Generated as `pc_...` unless you set one. |
| `status` | `pending`, `success`, `failed`, `amount_mismatch` or `refunded` (a `PaymentStatus` enum). |
| `total()`, `platformFee()`, `sellerShare()` | What the customer paid, your fee, and the seller's share before Paystack's own fee. |
| `paystack_fee` | Paystack's fee, once the payment has succeeded. |
| `channel`, `paid_at` | How and when the customer paid. |
| `failure_reason` | Paystack's reason when a payment failed. |
| `payable`, `subaccount` | The model being paid for, and the seller's subaccount. |
| `paystack_data` | Paystack's full transaction data, for anything else you need. |

`access_code` and `paystack_data` are left out of the model's JSON: one opens
the checkout, the other holds card and customer details.

On your callback page, confirm the payment straight away. `verify()` returns
`null` when no payment has that reference:

```php
$payment = PaystackConnect::verify($request->query('reference'));

if ($payment?->isSuccessful()) {
    return redirect()->route('invoices.show', $payment->payable)->with('status', 'Paid, thank you.');
}

return redirect()->route('invoices.index')->with('error', 'The payment did not go through.');
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
| `WebhookReceived` | Any verified webhook, including events this package doesn't handle itself. Fires before the webhook is marked handled. |
| `WebhookHandled` | A webhook was handled and the payment saved. Listen here when you need the payment's new state. |

To handle an event the package doesn't, such as a dispute, listen for
`WebhookReceived`. It carries the event name and Paystack's full payload:

```php
use Otatechie\PaystackConnect\Events\WebhookReceived;

Event::listen(function (WebhookReceived $event) {
    if ($event->event === 'charge.dispute.create') {
        // $event->payload['data'] ...
    }
});
```

A checkout the customer hasn't paid yet stays `pending`, even though Paystack
reports it as "abandoned" ([verify payments](https://paystack.com/docs/payments/verify-payments/)):
they can still come back and pay. To clean up old
unpaid checkouts, query pending payments older than you care about.

Listeners run once per payment, even when Paystack retries a webhook or two
deliveries overlap. If a listener throws, the webhook returns an error, the
event is kept, and Paystack's next retry processes it again. In live mode Paystack retries every
3 minutes for the first 4 tries, then hourly for 72 hours; in test mode,
hourly for 10 hours. You can also resend events from the Paystack dashboard
([webhooks](https://paystack.com/docs/payments/webhooks/)).

Paystack gives each delivery 30 seconds, so keep listeners quick and queue
slow work such as emails, as above.

### Keeping webhooks healthy

Add these to your scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;
use Otatechie\PaystackConnect\Models\WebhookEvent;

// Process again any webhook that failed, without waiting for Paystack.
Schedule::command('paystack-connect:retry-webhooks')->hourly();

// Remove processed webhooks older than webhook.keep_days (30 by default).
Schedule::command('model:prune', ['--model' => WebhookEvent::class])->daily();
```

Failed webhooks are never pruned, so they can always be retried. You can also
run `php artisan paystack-connect:retry-webhooks` by hand; it lists anything
still failing.

## Refunds

```php
PaystackConnect::refund($payment);                                  // everything
PaystackConnect::refund($payment, Money::major('50.00', 'GHS'));    // part of it
```

Paystack processes refunds in the background, which can take a while. Until
it does, the amount is held as pending (per refund, by Paystack's refund id),
so the same money can't be refunded twice. Refunds made from the Paystack
dashboard are recorded too when their webhook arrives. When the `refund.processed` webhook arrives, the payment's
`refunded_amount` goes up and `PaymentRefunded` is dispatched. Once the whole
amount is back, the status becomes `refunded`. If Paystack fails the refund
(`refund.failed`), the amount can be refunded again. If Paystack needs the
customer's bank details first (`refund.needs-attention`), the refund stays
pending until you provide them through Paystack's retry endpoint or dashboard
([refunds](https://paystack.com/docs/payments/refunds/)).

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
own rule uses the default alone. Payments without a seller have no fee.

The fee is never more than the payment, which means a payment below the
minimum fee goes entirely to you: a GHS 2 payment with a GHS 5 minimum leaves
the seller with nothing. If sellers sell cheap items, set a lower minimum or
enforce a minimum price. To preview a fee:

```php
PaystackConnect::feeFor(Money::major('100.00', 'GHS')); // GHS 5.00
```

By default your platform pays Paystack's own fee, out of your fee (`bearer`
set to `account`). Set it to `subaccount` to have sellers pay it instead.

## Currencies

Each Paystack account charges in its own country's currency, plus USD in some
countries if Paystack has enabled it for you. Minimums and the XOF rule below
are from Paystack's [supported currency table](https://paystack.com/docs/api/#supported-currency);
Egypt and Rwanda aren't in that table yet, though Paystack's API lists them. Anything else is refused with
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
so they are linked to the right model. Older ones are imported without an
owner; link each to its seller, which also records the owner on Paystack:

```php
PaystackConnect::subaccounts()->attach($business, 'ACCT_8f4s1eq7ml6rlzj');
```

To see bank and network codes:

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
| `requests()` | Everything sent to Paystack, for assertions of your own. |

The fake throws on any other endpoint. For those, use `Http::fake()`.

## Trying it against Paystack's test mode

- Pay with Paystack's "no validation" test card: `4084 0840 8408 4081`, CVV
  `408`, any future expiry. Other cards and channels are on Paystack's
  [test payments](https://paystack.com/docs/payments/test-payments/) page.
- Connecting a seller needs a real account or wallet number, even in test
  mode. No money moves. Paystack allows only 3 lookups of real accounts a day
  in test mode, and its test bank code `001` works for lookups but not for
  creating a subaccount. Neither is in Paystack's docs; both come from its
  API's own error messages.
- Webhooks need a public URL. A `.test` or `localhost` address won't work, so
  use a tunnel such as `herd share`, `expose` or `ngrok`, and put
  `https://<tunnel>/paystack/webhook` in the test Webhook URL field.
- Test refunds can stay pending for a while before Paystack processes them.

## Security

Every webhook's signature is checked against the raw request body. To also
accept webhooks only from Paystack's servers, uncomment their IP addresses
under `webhook.allowed_ips` in the config (the three IPs Paystack publishes on
its [webhooks](https://paystack.com/docs/payments/webhooks/) page). If your app sits behind a proxy or
load balancer, set up Laravel's trusted proxies first, or every webhook will
be rejected.

To add middleware in front of the webhook, such as a throttle, list it under
`webhook.middleware`. To register the route yourself, set `webhook.enabled`
to `false` and point your route at `WebhookController`, keeping the
`VerifyPaystackSignature` middleware.

Sellers' account numbers are encrypted in the database and left out of the
model's JSON. Only the last four digits are stored in the clear, for display.

## Paystack references

The package's behaviour follows these pages of Paystack's documentation:

- [Supported currencies](https://paystack.com/docs/api/#supported-currency): subunits, minimums, the XOF rule
- [Transaction API](https://paystack.com/docs/api/transaction/): checkout parameters, channels, references
- [Split payments](https://paystack.com/docs/payments/split-payments/) and [Subaccount API](https://paystack.com/docs/api/subaccount/): subaccounts, `transaction_charge`, `bearer`
- [Verify payments](https://paystack.com/docs/payments/verify-payments/): transaction statuses, including "abandoned"
- [Webhooks](https://paystack.com/docs/payments/webhooks/): signatures, IPs, retries, 30-second timeout
- [Refunds](https://paystack.com/docs/payments/refunds/): refund statuses and webhook events
- [Verify account number](https://paystack.com/docs/identity-verification/verify-account-number/): lookups in Ghana and Nigeria
- [Test payments](https://paystack.com/docs/payments/test-payments/): test cards

Two behaviours come from Paystack's API rather than its docs: the test-mode
limit on account lookups, and the `requested_amount` field that lets payments
settle when you pass Paystack's fee on to the customer.

## Contributing

```bash
composer test
composer analyse
composer format
```

## License

MIT. See [LICENSE.md](LICENSE.md).
