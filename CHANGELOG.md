# Changelog

All notable changes to this package are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed
- The package is on Packagist; installing no longer needs a `repositories` entry.
- CI also tests PHP 8.5 on Laravel 13.

### Documentation
- Claims about Paystack link to the Paystack documentation they come from, with a "Paystack references" section.
- The test card line no longer mentions a PIN and OTP, which Paystack doesn't document for that card.

## 1.0.0-beta.2 — 2026-09-23

### Added
- `PaystackConnect::subaccounts()->attach($owner, $code)` links an imported subaccount to its seller and records the owner on Paystack.
- Refunds made from the Paystack dashboard are recorded when their webhook arrives.
- `PaymentSucceeded` is dispatched when the merchant passes Paystack's fee on to the customer (`requested_amount` is compared instead of `amount`).
- Tests for the IP allow-list, refused checkouts, JSON output, optional checkout details and the fee pass-through.

### Changed
- Pending refunds are tracked per Paystack refund id (`pending_refunds`) instead of as one total. **Migration change:** `refund_pending` is replaced by `pending_refunds`.
- A webhook event is claimed before it is processed, so an overlapping delivery of the same payload is turned away (`{"status": "processing"}`) instead of running listeners twice. **Migration change:** `claimed_at` is added to `paystack_webhook_events`.
- `refund()` locks the payment row and checks what is refundable against the database, not the model passed in.
- Checkout refuses a seller whose subaccount settles in a different currency, a reference that already exists, and a reference with characters Paystack rejects, with clear messages.
- Re-running the import keeps owners that were linked locally.
- One subaccount per seller is enforced with a unique index on the owner, and `connect()` takes a lock per seller.

### Fixed
- Sellers' account numbers and contact details were stored in clear text inside `paystack_data`. They are now stripped, and `paystack_data` is hidden from JSON on both models. `Payment` also hides `access_code`.
- A `charge.success` payload without an amount or currency no longer decides the payment; the delivery fails and is retried.
- `canReceivePaystackPayments()` is correct straight after connecting, even if the relation was loaded before.
- Non-JSON replies from Paystack throw a `PaystackException` instead of a type error.

## 1.0.0-beta.1 — 2026-09-23

First beta: seller subaccounts, split checkout with platform fees, signed and deduplicated webhooks, refunds, and `PaystackConnect::fake()` for tests. Tested against Paystack's test mode with a Ghana account.
