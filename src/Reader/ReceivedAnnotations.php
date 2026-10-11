<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

/** The annotations of the invoice (`Adnotacje` and the flags next to it). */
final readonly class ReceivedAnnotations
{
    public function __construct(
        public bool $cashAccounting = false,
        public bool $selfBilling = false,
        public bool $reverseCharge = false,
        public bool $splitPayment = false,
        /** The legal basis of the VAT exemption (`P_19A`, `P_19B`, `P_19C`), when the invoice has exempt lines. */
        public ?string $exemptionBasis = null,
        public bool $triangular = false,
        public bool $marginScheme = false,
        public bool $newMeansOfTransport = false,
        public bool $invoiceUnderArt109 = false,
        public bool $relatedParties = false,
        public bool $exciseRefund = false,
    ) {}
}
