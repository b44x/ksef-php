<?php

declare(strict_types=1);

namespace B4x\Ksef\Export;

use DateTimeImmutable;

/**
 * A decrypted export saved as a ZIP file: `{ksefNumber}.xml` per invoice plus `_metadata.json`.
 *
 * For incremental synchronisation by `PermanentStorage` date, when {@see self::$isTruncated} is true start the
 * next export at {@see self::$continueFrom}; otherwise continue from {@see self::$permanentStorageHwmDate}.
 */
final readonly class ExportedPackage
{
    public function __construct(
        public string $path,
        public int $invoiceCount,
        public bool $isTruncated,
        public ?DateTimeImmutable $continueFrom,
        public ?DateTimeImmutable $permanentStorageHwmDate,
    ) {}
}
