<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Nip;

/** An entity authorised to issue the invoice for the seller: an enforcement authority, a court bailiff or a tax representative (`PodmiotUpowazniony`). */
final readonly class AuthorizedEntity
{
    public function __construct(
        public Nip $nip,
        public string $name,
        public Address $address,
        public AuthorizedEntityRole $role,
        public ?Address $correspondenceAddress = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $eori = null,
    ) {}
}
