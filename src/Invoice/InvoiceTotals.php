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
     * What remains of a sale after advances were paid: per rate, the tax contained in the advances is deducted from
     * the tax and the rest of the gross advance from the net value (art. 106f(3) of the VAT Act).
     *
     * @param list<AdvanceAmount> $advances
     */
    public function withoutAdvances(array $advances, string $currency, ?Decimal $exchangeRate): self
    {
        $buckets = [];
        foreach ($this->buckets as $bucket) {
            $net = $bucket->net;
            $vat = $bucket->vat;
            foreach ($advances as $advance) {
                if ($advance->rate->bucket() !== $bucket->key) {
                    continue;
                }
                $gross = $advance->gross->amount->roundTo(2);
                $percentage = $advance->rate->percentage();
                $advanceVat = $percentage !== null ? $gross->multiply($percentage)->dividedBy($percentage->add(Decimal::of(100)), 2) : Decimal::of('0.00');
                $net = $net->subtract($gross->subtract($advanceVat));
                $vat = $vat?->subtract($advanceVat);
            }
            $vatPln = $bucket->vatPln !== null && $vat !== null && $exchangeRate !== null ? $vat->multiply($exchangeRate)->roundTo(2) : $bucket->vatPln;
            $buckets[] = new TotalsBucket($bucket->key, $bucket->rate, $net, $vat, $vatPln);
        }

        return new self($buckets);
    }

    /**
     * Totals of an advance invoice: the tax is contained in the gross payment (`gross * rate / (100 + rate)`).
     */
    public static function forAdvance(AdvancePayment $advance, string $currency, ?Decimal $exchangeRate): self
    {
        $gross = $advance->paid->amount->roundTo(2);
        $percentage = $advance->rate->percentage();
        $vat = $percentage !== null ? $gross->multiply($percentage)->dividedBy($percentage->add(Decimal::of(100)), 2) : null;
        $net = $vat !== null ? $gross->subtract($vat) : $gross;
        $vatPln = $vat !== null && $currency !== 'PLN' && $exchangeRate !== null ? $vat->multiply($exchangeRate)->roundTo(2) : null;

        return new self([new TotalsBucket($advance->rate->bucket(), $advance->rate, $net, $vat, $vatPln)]);
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
