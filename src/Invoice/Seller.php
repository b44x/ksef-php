<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Nip;

/** The issuer of the invoice (`Podmiot1`). */
final readonly class Seller
{
    public function __construct(
        public Nip $nip,
        public string $name,
        public Address $address,
        public ?string $email = null,
        public ?string $phone = null,
        /** EORI number of the seller (`NrEORI`). */
        public ?string $eori = null,
        /** EU VAT prefix of the seller for the cases of art. 97(10) of the VAT Act (`PrefiksPodatnika`), for example "PL". */
        public ?string $vatPrefix = null,
        public ?Address $correspondenceAddress = null,
    ) {}
}
