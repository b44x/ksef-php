<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/**
 * Charges added to and deductions taken from the invoice total (`Rozliczenie`), for example packaging deposits or
 * a prepaid amount. KSeF shows the result either as the amount to pay or as an overpayment to return.
 */
final readonly class AdditionalSettlement
{
    /**
     * @param list<Adjustment> $charges
     * @param list<Adjustment> $deductions
     */
    public function __construct(
        public array $charges = [],
        public array $deductions = [],
    ) {}

    public function totalCharges(): Decimal
    {
        return array_reduce($this->charges, static fn(Decimal $sum, Adjustment $a): Decimal => $sum->add($a->amount->amount->roundTo(2)), Decimal::of('0.00'));
    }

    public function totalDeductions(): Decimal
    {
        return array_reduce($this->deductions, static fn(Decimal $sum, Adjustment $a): Decimal => $sum->add($a->amount->amount->roundTo(2)), Decimal::of('0.00'));
    }
}
