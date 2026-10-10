<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * Data of a final invoice (`ROZ`, art. 106f(3)) that settles earlier advances: which advance invoices it
 * refers to and how much was already paid in total (gross).
 *
 * The header of a final invoice (`P_13_x`, `P_14_x`, `P_15`) shows only what remains to be paid: the net value and the
 * tax of the advances are deducted from the sale. The SDK takes the tax out of the gross advances (`gross * rate /
 * (100 + rate)`) per VAT rate, which needs the rate: give {@see self::$advanceRate} when all advances had one rate (it is
 * inferred when the invoice has a single taxed rate), or {@see self::$advanceAmounts} when they had several.
 * On a `KOR_ROZ`, {@see self::$advancesPaid} is the *change* of the advances (usually zero).
 */
final readonly class Settlement
{
    /**
     * @param non-empty-list<AdvanceInvoiceReference> $advanceInvoices
     */
    public function __construct(
        public array $advanceInvoices,
        public Money $advancesPaid,
        public ?VatRate $advanceRate = null,
        /** @var list<AdvanceAmount> advances split by rate; when given, they must add up to {@see self::$advancesPaid} */
        public array $advanceAmounts = [],
    ) {}
}
