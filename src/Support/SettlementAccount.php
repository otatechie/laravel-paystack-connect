<?php

namespace Otatechie\PaystackConnect\Support;

/**
 * Where a seller wants to be paid: a bank account or a mobile money wallet.
 *
 * $bankCode is Paystack's code for the bank or network, taken from
 * PaystackConnect::banks()->list(...), never a display name.
 */
final class SettlementAccount
{
    public function __construct(
        public readonly string $businessName,
        public readonly string $bankCode,
        public readonly string $accountNumber,
        public readonly string $currency,
        public readonly string $accountType = 'bank',
        public readonly ?string $bankName = null,
        public readonly ?string $accountName = null,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
    ) {}

    public static function bank(string $businessName, string $bankCode, string $accountNumber, string $currency, ?string $bankName = null): self
    {
        return new self($businessName, $bankCode, $accountNumber, strtoupper($currency), 'bank', $bankName);
    }

    public static function mobileMoney(string $businessName, string $networkCode, string $phoneNumber, string $currency, ?string $networkName = null): self
    {
        return new self($businessName, $networkCode, $phoneNumber, strtoupper($currency), 'mobile_money', $networkName);
    }

    public function withAccountName(string $accountName): self
    {
        return new self(
            $this->businessName, $this->bankCode, $this->accountNumber, $this->currency, $this->accountType,
            $this->bankName, $accountName, $this->contactEmail, $this->contactName, $this->contactPhone,
        );
    }

    public function withContact(?string $email = null, ?string $name = null, ?string $phone = null): self
    {
        return new self(
            $this->businessName, $this->bankCode, $this->accountNumber, $this->currency, $this->accountType,
            $this->bankName, $this->accountName, $email, $name, $phone,
        );
    }
}
