<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Data type of an attachment table column (`Typ`). */
enum ColumnType: string
{
    case Date = 'date';
    case DateTime = 'datetime';
    case Decimal = 'dec';
    case Integer = 'int';
    case Time = 'time';
    case Text = 'txt';
}
