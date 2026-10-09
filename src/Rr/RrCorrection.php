<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Invoice\CorrectedInvoice;

/** Correction details of an RR invoice (`KOR_VAT_RR`). */
final readonly class RrCorrection
{
    /**
     * @param non-empty-list<CorrectedInvoice> $correctedInvoices
     */
    public function __construct(
        public array $correctedInvoices,
        public RrCorrectionType $type = RrCorrectionType::OriginalInvoiceDate,
        public ?string $reason = null,
    ) {}
}
