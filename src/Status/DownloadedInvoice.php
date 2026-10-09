<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use B4x\Ksef\Crypto\Digest;

/** An invoice XML retrieved from KSeF, with the hash KSeF reported for it. */
final readonly class DownloadedInvoice
{
    public function __construct(
        public string $ksefNumber,
        public string $xml,
        public string $hash,
    ) {}

    public function verifyHash(): bool
    {
        return hash_equals($this->hash, Digest::sha256Base64($this->xml));
    }
}
