<?php

declare(strict_types=1);

namespace B4x\Ksef\Export;

use DateTimeImmutable;

/** One downloadable, encrypted part (<= 50 MB) of an export package. */
final readonly class ExportPart
{
    public function __construct(
        public int $ordinalNumber,
        public string $url,
        public int $encryptedSize,
        public string $encryptedHash,
        public int $size,
        public string $hash,
        public DateTimeImmutable $expiresAt,
    ) {}
}
