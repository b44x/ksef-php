<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The recipient of the invoice (`Podmiot2`). */
final readonly class Buyer
{
    public function __construct(
        public BuyerIdentifier $identifier,
        public string $name,
        public ?Address $address = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $customerNumber = null,
        /** EORI number of the buyer (`NrEORI`). */
        public ?string $eori = null,
        public ?Address $correspondenceAddress = null,
        /** A key linking the buyer's data on correction invoices (`IDNabywcy`), up to 32 characters. */
        public ?string $buyerKey = null,
        /** The invoice concerns a subordinate unit of a local government (`JST`). */
        public bool $localGovernmentSubunit = false,
        /** The buyer is a member of a VAT group (`GV`). */
        public bool $vatGroupMember = false,
    ) {}
}
