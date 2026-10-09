<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/** One invoice position (`FaWiersz`), priced net. */
final readonly class InvoiceLine
{
    /**
     * @param Decimal $quantity up to six decimal places
     * @param Money $unitNetPrice up to eight decimal places
     */
    public function __construct(
        public string $name,
        public Decimal $quantity,
        public ?string $unit,
        public Money $unitNetPrice,
        public VatRate $vatRate,
        public ?Gtu $gtu = null,
        public ?string $pkwiu = null,
        public ?string $cn = null,
        public ?string $gtin = null,
        public ?string $internalCode = null,
        public LineState $state = LineState::Current,
    ) {}

    /**
     * @param string|int $unitNetPrice decimal string in the invoice currency
     */
    public static function of(string $name, string|int $quantity, ?string $unit, string|int $unitNetPrice, VatRate $vatRate, string $currency = 'PLN'): self
    {
        return new self($name, Decimal::of($quantity), $unit, Money::of($unitNetPrice, $currency), $vatRate);
    }

    /** The same line marked as the "before correction" state. */
    public function asBefore(): self
    {
        return new self($this->name, $this->quantity, $this->unit, $this->unitNetPrice, $this->vatRate, $this->gtu, $this->pkwiu, $this->cn, $this->gtin, $this->internalCode, LineState::Before);
    }

    public function withGtu(Gtu $gtu): self
    {
        return new self($this->name, $this->quantity, $this->unit, $this->unitNetPrice, $this->vatRate, $gtu, $this->pkwiu, $this->cn, $this->gtin, $this->internalCode, $this->state);
    }

    /** Net value of the line rounded to grosz (`P_11`). */
    public function netAmount(): Decimal
    {
        return $this->quantity->multiply($this->unitNetPrice->amount)->roundTo(2);
    }

    /** Net value with the sign it contributes to totals (negative for "before" lines). */
    public function signedNetAmount(): Decimal
    {
        return $this->state === LineState::Before ? $this->netAmount()->negate() : $this->netAmount();
    }
}
