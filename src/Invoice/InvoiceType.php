<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

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

    /** Documents an advance payment (`ZAL`); needs {@see AdvancePayment}, lines describe the order. */
    case Advance = 'ZAL';

    /** Final invoice settling advances (`ROZ`); needs {@see Settlement}. */
    case Settlement = 'ROZ';

    /** Simplified invoice (`UPR`, art. 106e(5)(3)): up to PLN 450 / EUR 100, buyer identified by NIP. */
    case Simplified = 'UPR';
}
