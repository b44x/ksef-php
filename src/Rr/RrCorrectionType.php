<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

/** Effect of an RR correction in the VAT register (`TypKorekty`). */
enum RrCorrectionType: int
{
    /** Effective on the date of the original invoice. */
    case OriginalInvoiceDate = 1;
    /** Effective on the date the correcting invoice was issued. */
    case CorrectionIssueDate = 2;
    /** Effective on another date. */
    case OtherDate = 3;
    /** Effective on the date the money was returned. */
    case RefundDate = 4;
}
