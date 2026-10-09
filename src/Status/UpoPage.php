<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use DateTimeImmutable;

/** Reference to one page of the aggregate session UPO. */
final readonly class UpoPage
{
    public function __construct(
        public string $referenceNumber,
        public string $downloadUrl,
        public DateTimeImmutable $downloadUrlExpiresAt,
    ) {}
}
