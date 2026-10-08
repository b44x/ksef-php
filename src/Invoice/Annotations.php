<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/**
 * Special-case markers of the invoice (`Adnotacje`).
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
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
