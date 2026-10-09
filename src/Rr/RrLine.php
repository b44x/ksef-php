<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Invoice\LineState;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/** One purchased agricultural product or service (`FakturaRRWiersz`). */
final readonly class RrLine
{
    /**
     * @param Decimal $quantity up to six decimal places
     * @param Decimal $unitPrice price without the refund, up to eight decimal places
     * @param string $quality class or quality of the product (`P_6C`)
     */
    public function __construct(
        public string $name,
        public string $unit,
        public Decimal $quantity,
        public string $quality,
        public Decimal $unitPrice,
        public RrRate $rate,
        public ?DateTimeImmutable $purchaseDate = null,
        public ?string $pkwiu = null,
        public ?string $cn = null,
        public ?string $gtin = null,
        public LineState $state = LineState::Current,
    ) {}

    public static function of(string $name, string $unit, string|int $quantity, string $quality, string|int $unitPrice, RrRate $rate, ?DateTimeImmutable $purchaseDate = null): self
    {
        return new self($name, $unit, Decimal::of($quantity), $quality, Decimal::of($unitPrice), $rate, $purchaseDate);
    }

    public function asBefore(): self
    {
        return new self($this->name, $this->unit, $this->quantity, $this->quality, $this->unitPrice, $this->rate, $this->purchaseDate, $this->pkwiu, $this->cn, $this->gtin, LineState::Before);
    }

    /** Value of the purchase without the refund (`P_8`). */
    public function value(): Decimal
    {
        return $this->quantity->multiply($this->unitPrice)->roundTo(2);
    }

    /** The flat-rate refund of tax (`P_10`). */
    public function refund(): Decimal
    {
        return $this->value()->percent($this->rate->percentage())->roundTo(2);
    }

    /** Value plus refund (`P_11`). */
    public function total(): Decimal
    {
        return $this->value()->add($this->refund());
    }

    public function sign(Decimal $amount): Decimal
    {
        return $this->state === LineState::Before ? $amount->negate() : $amount;
    }
}
