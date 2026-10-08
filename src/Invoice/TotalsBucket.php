<?php

declare(strict_types=1);

namespace Ksef\Invoice;

use Ksef\Support\Decimal;

/** Net and tax sums for one header bucket (`P_13_x` / `P_14_x`). */
final readonly class TotalsBucket
{
    /**
     * @param string $key bucket suffix, see {@see VatRate::bucket()}
     * @param Decimal|null $vat null for non-taxed buckets
     * @param Decimal|null $vatPln tax converted to PLN (`P_14_xW`) for foreign-currency invoices
     */
    public function __construct(
        public string $key,
        public VatRate $rate,
        public Decimal $net,
        public ?Decimal $vat,
        public ?Decimal $vatPln,
    ) {}
}
