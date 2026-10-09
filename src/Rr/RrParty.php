<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Support\Nip;

/** The supplier (a flat-rate farmer, `Podmiot1`) or the buyer who issues the invoice (`Podmiot2`) of an RR invoice. */
final readonly class RrParty
{
    public function __construct(
        public Nip $nip,
        public string $name,
        public Address $address,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
