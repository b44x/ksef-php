<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

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
        public ?Money $discount = null,
        public ?DateTimeImmutable $deliveryDate = null,
        public ?LineProcedure $procedure = null,
        public ?Money $excise = null,
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
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $this->gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: LineState::Before,
            discount: $this->discount,
            deliveryDate: $this->deliveryDate,
            procedure: $this->procedure,
            excise: $this->excise,
        );
    }

    public function withGtu(Gtu $gtu): self
    {
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: $this->state,
            discount: $this->discount,
            deliveryDate: $this->deliveryDate,
            procedure: $this->procedure,
            excise: $this->excise,
        );
    }

    /**
     * A discount or price reduction for the whole line in the invoice currency, not already included in the unit
     * price (`P_10`); it is subtracted from the net value.
     */
    public function withDiscount(string|int $amount, string $currency = 'PLN'): self
    {
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $this->gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: $this->state,
            discount: Money::of($amount, $currency),
            deliveryDate: $this->deliveryDate,
            procedure: $this->procedure,
            excise: $this->excise,
        );
    }

    /** The date the goods were delivered or the service performed, when it differs from the invoice (`P_6A`). */
    public function deliveredOn(DateTimeImmutable $date): self
    {
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $this->gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: $this->state,
            discount: $this->discount,
            deliveryDate: $date,
            procedure: $this->procedure,
            excise: $this->excise,
        );
    }

    /** Marks a special VAT procedure for this line (`Procedura`). */
    public function withProcedure(LineProcedure $procedure): self
    {
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $this->gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: $this->state,
            discount: $this->discount,
            deliveryDate: $this->deliveryDate,
            procedure: $procedure,
            excise: $this->excise,
        );
    }

    /** The excise tax contained in the price (`KwotaAkcyzy`). */
    public function withExcise(string|int $amount, string $currency = 'PLN'): self
    {
        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            unitNetPrice: $this->unitNetPrice,
            vatRate: $this->vatRate,
            gtu: $this->gtu,
            pkwiu: $this->pkwiu,
            cn: $this->cn,
            gtin: $this->gtin,
            internalCode: $this->internalCode,
            state: $this->state,
            discount: $this->discount,
            deliveryDate: $this->deliveryDate,
            procedure: $this->procedure,
            excise: Money::of($amount, $currency),
        );
    }

    /** Net value of the line rounded to grosz (`P_11`). */
    public function netAmount(): Decimal
    {
        $gross = $this->quantity->multiply($this->unitNetPrice->amount);

        return ($this->discount !== null ? $gross->subtract($this->discount->amount) : $gross)->roundTo(2);
    }

    /** Net value with the sign it contributes to totals (negative for "before" lines). */
    public function signedNetAmount(): Decimal
    {
        return $this->state === LineState::Before ? $this->netAmount()->negate() : $this->netAmount();
    }
}
