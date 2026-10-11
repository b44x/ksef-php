<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/** An invoice line (`FaWiersz`). Net-based and gross-based invoices fill different amounts; the others are null. */
final readonly class ReceivedLine
{
    public function __construct(
        public int $number,
        public string $name,
        public ?string $unit = null,
        public ?Decimal $quantity = null,
        public ?Decimal $unitPriceNet = null,
        public ?Decimal $unitPriceGross = null,
        public ?Decimal $discount = null,
        public ?Decimal $netAmount = null,
        public ?Decimal $grossAmount = null,
        public ?Decimal $vatAmount = null,
        /** The rate as written (`P_12`): "23", "8", "zw", "0 KR", "np I", "oo", ... */
        public string $vatRate = '',
        public ?string $gtu = null,
        public ?string $procedure = null,
        public ?string $internalCode = null,
        public ?string $gtin = null,
        public ?string $pkwiu = null,
        public ?string $cn = null,
        public ?DateTimeImmutable $deliveryDate = null,
        /** True for the "before the correction" line of a correction (`StanPrzed`). */
        public bool $before = false,
    ) {}

    /** The rate as a {@see VatRate} when it is one the typed model knows. */
    public function rate(): ?VatRate
    {
        return VatRate::tryFrom($this->vatRate);
    }
}
