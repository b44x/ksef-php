<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * Invoice kinds that can be modelled with the typed API (`RodzajFaktury`).
 *
 * All kinds of FA(3) are modelled. For anything else submit raw XML with {@see InvoiceDocument::fromXml()}.
 */
enum InvoiceType: string
{
    case Standard = 'VAT';
    case Correction = 'KOR';

    /** Documents an advance payment (`ZAL`); needs {@see AdvancePayment}, lines describe the order. */
    case Advance = 'ZAL';

    /** Final invoice settling advances (`ROZ`); needs {@see Settlement}. */
    case Settlement = 'ROZ';

    /** Simplified invoice (`UPR`, art. 106e(5)(3)): up to PLN 450 / EUR 100, buyer identified by NIP. */
    case Simplified = 'UPR';

    /**
     * Correction of an advance invoice (`KOR_ZAL`): {@see AdvancePayment::$paid} is the change of the payment
     * (negative when it decreases), lines describe the order before and after the correction.
     */
    case AdvanceCorrection = 'KOR_ZAL';

    /** Correction of a settlement invoice (`KOR_ROZ`): before/after lines plus {@see Settlement}, amounts are differences. */
    case SettlementCorrection = 'KOR_ROZ';

    /** KOR, KOR_ZAL and KOR_ROZ: documents that need {@see Correction} data and "before" lines. */
    public function isCorrection(): bool
    {
        return $this === self::Correction || $this === self::AdvanceCorrection || $this === self::SettlementCorrection;
    }

    /** ZAL and KOR_ZAL: documents built from an {@see AdvancePayment} whose lines describe the order. */
    public function isAdvance(): bool
    {
        return $this === self::Advance || $this === self::AdvanceCorrection;
    }

    /** ROZ and KOR_ROZ: documents built from a {@see Settlement}. */
    public function isSettlement(): bool
    {
        return $this === self::Settlement || $this === self::SettlementCorrection;
    }
}
