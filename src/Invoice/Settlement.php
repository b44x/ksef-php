<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * Data of a final invoice (`ROZ`, art. 106f(3)) that settles earlier advances: which advance invoices it
 * refers to and how much was already paid in total (gross). The amount still due (`P_15`) is the invoice
 * total minus {@see self::$advancesPaid}.
 */
final readonly class Settlement
{
    /**
     * @param non-empty-list<AdvanceInvoiceReference> $advanceInvoices
     */
    public function __construct(
        public array $advanceInvoices,
        public Money $advancesPaid,
    ) {}
}
