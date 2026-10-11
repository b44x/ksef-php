<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Invoice\Address;

/**
 * A party of a received invoice: seller (`Podmiot1`), buyer (`Podmiot2`) or an additional party (`Podmiot3`).
 *
 * Identifiers are kept exactly as written on the invoice (not validated): a Polish NIP, an EU VAT number with its
 * country prefix, another tax number, or none.
 */
final readonly class ReceivedParty
{
    public function __construct(
        public string $name,
        public ?string $nip = null,
        public ?string $euVatCountry = null,
        public ?string $euVatNumber = null,
        public ?string $foreignIdCountry = null,
        public ?string $foreignId = null,
        public bool $withoutIdentifier = false,
        public ?string $eori = null,
        public ?Address $address = null,
        public ?Address $correspondenceAddress = null,
        public ?string $email = null,
        public ?string $phone = null,
        /** For additional parties: the role code of FA(3) (`Rola`, see {@see \B4x\Ksef\Invoice\ThirdPartyRole}) or null. */
        public ?string $roleCode = null,
        /** For additional parties with another role: the description (`OpisRoli`). */
        public ?string $roleDescription = null,
        public ?string $customerNumber = null,
        public ?string $vatPrefix = null,
    ) {}

    /** The tax identifier as one printable string, for example `5265877635` or `DE123456789`; null when there is none. */
    public function taxId(): ?string
    {
        if ($this->nip !== null) {
            return $this->nip;
        }
        if ($this->euVatNumber !== null) {
            return ($this->euVatCountry ?? '') . $this->euVatNumber;
        }
        if ($this->foreignId !== null) {
            return ($this->foreignIdCountry ?? '') . $this->foreignId;
        }

        return null;
    }
}
