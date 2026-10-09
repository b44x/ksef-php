<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/**
 * Amounts derived from the lines: per-bucket net and tax, and the gross total (`P_15`).
 *
 * Tax is computed per bucket on the summed net value (not per line) and rounded half away from
 * zero to the grosz, which is the calculation method the Polish VAT Act prescribes.
 * On correction invoices "before" lines are subtracted, so the figures are differences.
 */
final readonly class InvoiceTotals
{
    /**
     * @param list<TotalsBucket> $buckets in schema order
     */
    public function __construct(public array $buckets) {}

    public function net(): Decimal
    {
        return array_reduce($this->buckets, static fn(Decimal $sum, TotalsBucket $b): Decimal => $sum->add($b->net), Decimal::of('0.00'));
    }

    public function vat(): Decimal
    {
        return array_reduce($this->buckets, static fn(Decimal $sum, TotalsBucket $b): Decimal => $b->vat === null ? $sum : $sum->add($b->vat), Decimal::of('0.00'));
    }

    public function gross(): Decimal
    {
        return $this->net()->add($this->vat());
    }

    /**
     * @param list<InvoiceLine> $lines
     */
    public static function calculate(array $lines, string $currency, ?Decimal $exchangeRate): self
    {
        $order = ['1', '2', '3', '6_1', '6_2', '6_3', '7', '8', '9', '10'];

        /** @var array<string, array{VatRate, Decimal}> $sums */
        $sums = [];
        foreach ($lines as $line) {
            $key = $line->vatRate->bucket();
            $sums[$key] = [$line->vatRate, ($sums[$key][1] ?? Decimal::of('0.00'))->add($line->signedNetAmount())];
        }

        $buckets = [];
        foreach ($order as $key) {
            if (!isset($sums[$key])) {
                continue;
            }
            [$rate, $net] = $sums[$key];
            $percentage = $rate->percentage();
            $vat = $percentage !== null ? $net->percent($percentage)->roundTo(2) : null;
            $vatPln = $vat !== null && $currency !== 'PLN' && $exchangeRate !== null ? $vat->multiply($exchangeRate)->roundTo(2) : null;
            $buckets[] = new TotalsBucket($key, $rate, $net->roundTo(2), $vat, $vatPln);
        }

        return new self($buckets);
    }
}
