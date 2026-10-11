<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use DateTimeImmutable;

/** An invoice that a correction refers to (`DaneFaKorygowanej`). */
final readonly class ReceivedCorrectedInvoice
{
    public function __construct(
        public ?DateTimeImmutable $issueDate,
        public string $number,
        /** Null when the corrected invoice was issued outside KSeF. */
        public ?string $ksefNumber = null,
    ) {}
}
