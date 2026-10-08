<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/**
 * Invoice kinds that can be modelled with the typed API (`RodzajFaktury`).
 *
 * Advance (ZAL), settlement (ROZ), simplified (UPR) and their corrections are not modelled yet;
 * submit such documents as raw XML with {@see InvoiceDocument::fromXml()}.
 */
enum InvoiceType: string
{
    case Standard = 'VAT';
    case Correction = 'KOR';
}
