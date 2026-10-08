<?php

declare(strict_types=1);

namespace Ksef\Status;

use Ksef\Crypto\Digest;

/** An Official Confirmation of Receipt (UPO): an XML document signed by the Ministry of Finance. */
final readonly class Upo
{
    public function __construct(
        public string $xml,
        public string $hash,
    ) {}

    public function verifyHash(): bool
    {
        return hash_equals($this->hash, Digest::sha256Base64($this->xml));
    }
}
