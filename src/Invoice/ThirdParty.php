<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/**
 * An additional party named on the invoice (`Podmiot3`): a recipient, a payer, a factor and so on.
 *
 * The role is either one of the standard {@see ThirdPartyRole} values or a free-text description.
 */
final readonly class ThirdParty
{
    /**
     * @param ThirdPartyRole|null $role the standard role; null when {@see $otherRole} describes it
     * @param string|null $otherRole free-text role (`OpisRoli`) used when no standard role fits
     * @param Decimal|null $share percentage share of this party (0 to 100), where it applies
     * @param string|null $eori EORI number
     */
    private function __construct(
        public BuyerIdentifier $identifier,
        public string $name,
        public ?ThirdPartyRole $role,
        public ?string $otherRole = null,
        public ?Address $address = null,
        public ?Address $correspondenceAddress = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?Decimal $share = null,
        public ?string $customerNumber = null,
        public ?string $eori = null,
    ) {}

    public static function of(
        ThirdPartyRole $role,
        BuyerIdentifier $identifier,
        string $name,
        ?Address $address = null,
        ?Address $correspondenceAddress = null,
        ?string $email = null,
        ?string $phone = null,
        ?Decimal $share = null,
        ?string $customerNumber = null,
        ?string $eori = null,
    ): self {
        return new self($identifier, $name, $role, null, $address, $correspondenceAddress, $email, $phone, $share, $customerNumber, $eori);
    }

    public static function withOtherRole(
        string $roleDescription,
        BuyerIdentifier $identifier,
        string $name,
        ?Address $address = null,
        ?Address $correspondenceAddress = null,
        ?string $email = null,
        ?string $phone = null,
        ?Decimal $share = null,
        ?string $customerNumber = null,
        ?string $eori = null,
    ): self {
        return new self($identifier, $name, null, $roleDescription, $address, $correspondenceAddress, $email, $phone, $share, $customerNumber, $eori);
    }
}
