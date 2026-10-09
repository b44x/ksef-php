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
    ) {}
}
