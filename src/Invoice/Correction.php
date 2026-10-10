<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/** Correction details required on invoices of type {@see InvoiceType::Correction}. */
final readonly class Correction
{
    /**
     * @param non-empty-list<CorrectedInvoice> $correctedInvoices
     * @param Seller|null $sellerBefore the full seller data as on the corrected invoice, when the correction changes it (`Podmiot1K`)
     * @param list<Buyer> $buyersBefore the buyers' data as on the corrected invoice, when the correction changes it (`Podmiot2K`);
     *                                  give each a `buyerKey` shared with the corrected buyer data
     * @param Money|null $amountBefore for `KOR_ZAL`: the payment documented by the corrected advance invoice; for `KOR_ROZ`:
     *                                 the amount that remained to be paid before the correction (`P_15ZK`)
     * @param Decimal|null $exchangeRateBefore the exchange rate used before the correction, for foreign currency (`KursWalutyZK`)
     * @param string|null $period makes this a collective correction under art. 106j(3): the period the discount or price
     *                            reduction refers to (`OkresFaKorygowanej`, a date range or free text). The invoice then has
     *                            no lines; give the corrections of the tax base and tax in {@see self::$amounts}.
     * @param list<CorrectionAmount> $amounts the differences per VAT rate of a collective correction
     */
    public function __construct(
        public array $correctedInvoices,
        public CorrectionType $type = CorrectionType::OriginalInvoicePeriod,
        public ?string $reason = null,
        public ?Seller $sellerBefore = null,
        public array $buyersBefore = [],
        public ?Money $amountBefore = null,
        public ?Decimal $exchangeRateBefore = null,
        public ?string $period = null,
        public array $amounts = [],
    ) {}
}
