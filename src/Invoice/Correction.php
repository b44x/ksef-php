<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Correction details required on invoices of type {@see InvoiceType::Correction}. */
final readonly class Correction
{
    /**
     * @param non-empty-list<CorrectedInvoice> $correctedInvoices
     * @param Seller|null $sellerBefore the full seller data as on the corrected invoice, when the correction changes it (`Podmiot1K`)
     * @param list<Buyer> $buyersBefore the buyers' data as on the corrected invoice, when the correction changes it (`Podmiot2K`);
     *                                  give each a `buyerKey` shared with the corrected buyer data
     */
    public function __construct(
        public array $correctedInvoices,
        public CorrectionType $type = CorrectionType::OriginalInvoicePeriod,
        public ?string $reason = null,
        public ?Seller $sellerBefore = null,
        public array $buyersBefore = [],
    ) {}
}
