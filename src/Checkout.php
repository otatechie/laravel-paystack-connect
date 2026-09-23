<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Otatechie\PaystackConnect\Enums\PaymentStatus;
use Otatechie\PaystackConnect\Exceptions\PaystackException;
use Otatechie\PaystackConnect\Http\PaystackClient;
use Otatechie\PaystackConnect\Models\Payment;
use Otatechie\PaystackConnect\Models\Subaccount;
use Otatechie\PaystackConnect\Support\Money;

/**
 * Builds a payment and sends the customer to Paystack.
 *
 *     $payment = PaystackConnect::checkout()
 *         ->amount('250.00', 'GHS')
 *         ->email($client->email)
 *         ->seller($business)
 *         ->for($invoice)
 *         ->callbackUrl(route('invoices.paid', $invoice))
 *         ->create();
 *
 *     return redirect($payment->authorization_url);
 */
class Checkout
{
    private ?Money $amount = null;

    private ?string $email = null;

    private ?Subaccount $subaccount = null;

    private ?Model $payable = null;

    private ?string $reference = null;

    private ?string $callbackUrl = null;

    private Money|string|int|float|null $fee = null;

    private ?string $bearer = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** @var list<string> */
    private array $channels = [];

    public function __construct(
        private readonly PaystackClient $client,
        private readonly Subaccounts $subaccounts,
        private readonly Fees $fees,
        private readonly string $defaultBearer,
    ) {}

    /** Accepts Money, or a major-unit amount such as "250.00". */
    public function amount(Money|string|int|float $amount, ?string $currency = null): self
    {
        $this->amount = $amount instanceof Money ? $amount : Money::major($amount, $currency);

        return $this;
    }

    public function email(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /** The seller who receives the payment: a model with a connected subaccount, or the subaccount itself. */
    public function seller(Model|Subaccount $seller): self
    {
        $subaccount = $seller instanceof Subaccount ? $seller : $this->subaccounts->for($seller);

        if (! $subaccount || ! $subaccount->active) {
            throw new InvalidArgumentException('This seller has no active Paystack subaccount. Connect their account first.');
        }

        $this->subaccount = $subaccount;

        return $this;
    }

    /** What the payment is for, such as an invoice or order. */
    public function for(Model $payable): self
    {
        $this->payable = $payable;

        return $this;
    }

    public function reference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function callbackUrl(string $url): self
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /** Override the fee from config for this one payment. A major-unit amount is in the payment's currency. */
    public function fee(Money|string|int|float $fee): self
    {
        $this->fee = $fee;

        return $this;
    }

    /** Who pays Paystack's fee: 'account' (your platform) or 'subaccount' (the seller). */
    public function bearer(string $bearer): self
    {
        if (! in_array($bearer, ['account', 'subaccount'], true)) {
            throw new InvalidArgumentException("Bearer must be 'account' or 'subaccount', not '{$bearer}'.");
        }

        $this->bearer = $bearer;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** @param list<string> $channels For example ['card', 'mobile_money']. */
    public function channels(array $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Record the payment and start it on Paystack.
     *
     * @return Payment With authorization_url set: redirect the customer there,
     *                 or pass access_code to Paystack's inline popup.
     */
    public function create(): Payment
    {
        if (! $this->amount || $this->amount->isZero()) {
            throw new InvalidArgumentException('Set a payment amount above zero.');
        }

        if (! $this->email) {
            throw new InvalidArgumentException('Set the customer email.');
        }

        $fee = Money::minor(0, $this->amount->currency);

        if ($this->subaccount) {
            $fee = match (true) {
                $this->fee === null => $this->fees->for($this->amount),
                $this->fee instanceof Money => $this->fee,
                default => Money::major($this->fee, $this->amount->currency),
            };

            $fee = $fee->min($this->amount);
        }

        $payment = Payment::create([
            'reference' => $this->reference ?? 'pc_'.Str::lower((string) Str::ulid()),
            'subaccount_id' => $this->subaccount?->id,
            'payable_type' => $this->payable?->getMorphClass(),
            'payable_id' => $this->payable?->getKey(),
            'email' => $this->email,
            'amount' => $this->amount->minor,
            'platform_fee' => $fee->minor,
            'currency' => $this->amount->currency,
            'status' => PaymentStatus::Pending,
            'metadata' => $this->metadata ?: null,
        ]);

        $payload = array_filter([
            'email' => $this->email,
            'amount' => $this->amount->minor,
            'currency' => $this->amount->currency,
            'reference' => $payment->reference,
            'callback_url' => $this->callbackUrl,
            'channels' => $this->channels ?: null,
            'metadata' => json_encode([...$this->metadata, 'paystack_connect_payment_id' => $payment->id]),
        ], fn ($value) => $value !== null);

        if ($this->subaccount) {
            $payload['subaccount'] = $this->subaccount->subaccount_code;
            $payload['transaction_charge'] = $fee->minor;
            $payload['bearer'] = $this->bearer ?? $this->defaultBearer;
        }

        try {
            $data = $this->client->post('/transaction/initialize', $payload)['data'];
        } catch (PaystackException $e) {
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => $e->getMessage()]);

            throw $e;
        }

        $payment->update([
            'access_code' => $data['access_code'] ?? null,
            'authorization_url' => $data['authorization_url'] ?? null,
        ]);

        return $payment;
    }
}
