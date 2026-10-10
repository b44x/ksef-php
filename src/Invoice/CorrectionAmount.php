<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/**
 * The correction of the tax base and of the tax at one VAT rate, for a collective correction that has no lines
 * (art. 106j(3) of the VAT Act). Amounts are differences: a discount is negative.
 */
final readonly class CorrectionAmount
{
    public function __construct(
        public VatRate $rate,
        public Decimal $net,
        /** The correction of the tax; null for rates without tax (zero, exempt, ...). */
        public ?Decimal $vat = null,
    ) {}

    public static function of(VatRate $rate, string|int $net, string|int|null $vat = null): self
    {
        return new self($rate, Decimal::of($net), $vat === null ? null : Decimal::of($vat));
    }
}
