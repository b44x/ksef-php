<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/** Role of a line on a correction invoice. Standard invoices only contain {@see self::Current}. */
enum LineState
{
    /** The line as it is after the correction (or any line of a standard invoice). */
    case Current;

    /** The line as it was on the corrected invoice (`StanPrzed`). Its amounts are subtracted. */
    case Before;
}
