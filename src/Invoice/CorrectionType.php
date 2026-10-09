<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Effective date of a correction (`TypKorekty`). */
enum CorrectionType: int
{
    /** Effective in the period of the original invoice. */
    case OriginalInvoicePeriod = 1;

    /** Effective in the period of the correcting invoice. */
    case CorrectionInvoicePeriod = 2;

    /** Effective in another period, including lines with different dates. */
    case OtherPeriod = 3;
}
