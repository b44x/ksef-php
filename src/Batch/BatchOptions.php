<?php

declare(strict_types=1);

namespace B4x\Ksef\Batch;

use B4x\Ksef\Invoice\FormCode;

/**
 * How {@see \B4x\Ksef\KsefClient::sendBatch()} packs and declares a batch.
 */
final readonly class BatchOptions
{
    /** Largest part KSeF accepts (before encryption). */
    public const DEFAULT_MAX_PART_BYTES = BatchPackager::DEFAULT_MAX_PART_BYTES;

    /**
     * @param int $maxPartBytes size at which the archive is split into parts (KSeF allows up to 100 MB per part)
     * @param FormCode|null $formCode the schema of every invoice in the batch; FA(3) when null
     * @param bool $offline declare the invoices as issued in offline mode
     */
    public function __construct(
        public int $maxPartBytes = self::DEFAULT_MAX_PART_BYTES,
        public ?FormCode $formCode = null,
        public bool $offline = false,
    ) {}

    /** A batch of offline invoices (see docs/OFFLINE.md). */
    public static function offline(?FormCode $formCode = null): self
    {
        return new self(formCode: $formCode, offline: true);
    }
}
