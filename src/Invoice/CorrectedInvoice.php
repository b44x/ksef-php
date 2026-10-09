<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/** Reference to an invoice that a correction refers to (`DaneFaKorygowanej`). */
final readonly class CorrectedInvoice
{
    /**
     * @param DateTimeImmutable $issueDate the issue date of the corrected invoice
     * @param string|null $ksefNumber the KSeF number if the original was issued through KSeF
     */
    public function __construct(
        public DateTimeImmutable $issueDate,
        public string $number,
        public ?string $ksefNumber = null,
    ) {}
}
