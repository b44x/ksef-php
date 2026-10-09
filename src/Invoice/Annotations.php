<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * Special-case markers of the invoice (`Adnotacje` and a few flags beside it).
 *
 * Reverse charge (`P_18`) and the VAT exemption (`P_19`) are derived from the lines, so they cannot
 * contradict the line data; `exemptionBasis` states the legal basis when a line is exempt.
 */
final readonly class Annotations
{
    public function __construct(
        public bool $cashAccounting = false,
        public bool $selfBilling = false,
        public bool $splitPayment = false,
        public ?string $exemptionBasis = null,
        /** Margin procedure (`PMarzy`). */
        public ?MarginScheme $marginScheme = null,
        /** Simplified procedure for a triangular transaction by the second taxpayer (`P_23`). */
        public bool $triangular = false,
        /** Related parties (`TP`): the buyer and the seller are linked as described in art. 32(2)(1) of the VAT Act. */
        public bool $relatedParties = false,
        /** Invoice under art. 109(3d) of the VAT Act (`FP`). */
        public bool $invoiceUnderArt109 = false,
        /** Excise refund information for farmers (`ZwrotAkcyzy`). */
        public bool $exciseRefund = false,
        /** Intra-Community supply of new means of transport (`NoweSrodkiTransportu`). */
        public ?NewTransportSupply $newTransport = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
