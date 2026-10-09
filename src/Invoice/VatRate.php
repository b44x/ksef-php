<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/**
 * Tax rate or tax treatment of a line (`P_12`). Case values are the exact FA(3) codes.
 *
 * Several cases share one totals bucket in the invoice header (for example 23% and 22%); an
 * invoice may only use one rate per bucket.
 */
enum VatRate: string
{
    case Rate23 = '23';
    case Rate22 = '22';
    case Rate8 = '8';
    case Rate7 = '7';
    case Rate5 = '5';
    /** 0%: domestic sales, excluding intra-community supply and export. */
    case ZeroDomestic = '0 KR';
    /** 0%: intra-community supply of goods. */
    case ZeroIntraCommunity = '0 WDT';
    /** 0%: export of goods. */
    case ZeroExport = '0 EX';
    /** Exempt from VAT ("zwolnione"). Requires a legal basis on the invoice. */
    case Exempt = 'zw';
    /** Reverse charge ("odwrotne obciążenie"). */
    case ReverseCharge = 'oo';
    /** Not subject to Polish VAT: supply outside the territory (`np I`). */
    case NotSubjectOutsideTerritory = 'np I';
    /** Not subject to Polish VAT: services under art. 100(1)(4) (`np II`). */
    case NotSubjectArt100 = 'np II';

    public function isTaxed(): bool
    {
        return match ($this) {
            self::Rate23, self::Rate22, self::Rate8, self::Rate7, self::Rate5 => true,
            default => false,
        };
    }

    public function percentage(): ?Decimal
    {
        return $this->isTaxed() ? Decimal::of($this->value) : null;
    }

    /**
     * Suffix of the header fields: `1` → `P_13_1`/`P_14_1`, `6_1` → `P_13_6_1`, and so on.
     */
    public function bucket(): string
    {
        return match ($this) {
            self::Rate23, self::Rate22 => '1',
            self::Rate8, self::Rate7 => '2',
            self::Rate5 => '3',
            self::ZeroDomestic => '6_1',
            self::ZeroIntraCommunity => '6_2',
            self::ZeroExport => '6_3',
            self::Exempt => '7',
            self::NotSubjectOutsideTerritory => '8',
            self::NotSubjectArt100 => '9',
            self::ReverseCharge => '10',
        };
    }
}
