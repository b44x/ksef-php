<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Correction details required on invoices of type {@see InvoiceType::Correction}. */
final readonly class Correction
{
    /**
     * @param non-empty-list<CorrectedInvoice> $correctedInvoices
     */
    public function __construct(
        public array $correctedInvoices,
        public CorrectionType $type = CorrectionType::OriginalInvoicePeriod,
        public ?string $reason = null,
    ) {}
}
