<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/** An EU entity that is given the right to self-invoice (see `KsefClient::grantEuEntityAdministrator()`). */
final readonly class EuEntity
{
    /**
     * @param string $vatUe NIP-VAT UE identifier: the seller's NIP, a dash and the EU VAT number
     * @param string $name full name (5 to 100 characters)
     * @param string $address address (up to 512 characters)
     */
    public function __construct(
        public string $vatUe,
        public string $name,
        public string $address,
    ) {}
}
