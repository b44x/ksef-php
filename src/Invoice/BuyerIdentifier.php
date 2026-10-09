<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Nip;

/** How the buyer is identified for tax purposes (`DaneIdentyfikacyjne` of `Podmiot2`). */
final readonly class BuyerIdentifier
{
    private function __construct(
        public BuyerIdentifierType $type,
        public ?string $value = null,
        public ?string $countryCode = null,
    ) {}

    /** A Polish taxpayer. */
    public static function nip(Nip $nip): self
    {
        return new self(BuyerIdentifierType::Nip, $nip->value);
    }

    /** An EU VAT payer: country prefix (for example "DE") and the number without the prefix. */
    public static function euVat(string $countryCode, string $number): self
    {
        return new self(BuyerIdentifierType::EuVat, $number, $countryCode);
    }

    /** A buyer identified by a non-EU tax number; the issuing country is optional. */
    public static function foreign(string $number, ?string $countryCode = null): self
    {
        return new self(BuyerIdentifierType::Foreign, $number, $countryCode);
    }

    /** A buyer without any tax identifier (for example a consumer). */
    public static function none(): self
    {
        return new self(BuyerIdentifierType::None);
    }
}
