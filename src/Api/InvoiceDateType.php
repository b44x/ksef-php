<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

/** Which date the search range applies to. Use {@see self::PermanentStorage} for incremental synchronisation. */
enum InvoiceDateType: string
{
    case Issue = 'Issue';
    case Invoicing = 'Invoicing';
    case PermanentStorage = 'PermanentStorage';
}
