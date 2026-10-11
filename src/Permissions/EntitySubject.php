<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Support\Nip;

/**
 * A company or other entity a permission or authorisation is granted to: who it is and the name KSeF stores
 * with the grant (5 to 90 characters).
 */
final readonly class EntitySubject
{
    private function __construct(
        public Nip|string $identifier,
        public string $fullName,
    ) {}

    public static function nip(Nip $nip, string $fullName): self
    {
        return new self($nip, $fullName);
    }

    /** A Peppol service provider, identified by its Peppol ID (for example "PGZ487830"). */
    public static function peppolProvider(string $peppolId, string $fullName): self
    {
        return new self($peppolId, $fullName);
    }
}
