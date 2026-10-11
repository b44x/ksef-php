<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Support\Decimal;

/**
 * One row of the tax summary in the invoice header: the net value per rate group (`P_13_x`) with its tax (`P_14_x`).
 *
 * The groups of FA(3): `1` 23%/22%, `2` 8%/7%, `3` 5%, `4` taxi flat rate, `5` OSS procedure, `6_1` 0% domestic,
 * `6_2` 0% intra-Community, `6_3` 0% export, `7` exempt, `8` not subject (outside Poland), `9` not subject
 * (art. 100(1)(4)), `10` reverse charge, `11` margin schemes.
 */
final readonly class TaxSummaryRow
{
    public function __construct(
        public string $group,
        public Decimal $net,
        /** Null for groups without tax (exempt, reverse charge, ...). */
        public ?Decimal $tax = null,
        /** The tax converted to PLN for foreign-currency invoices (`P_14_xW`). */
        public ?Decimal $taxPln = null,
    ) {}
}
