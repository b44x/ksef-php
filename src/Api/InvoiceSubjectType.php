<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

/** The role of the authenticated context in the invoices being searched. */
enum InvoiceSubjectType: string
{
    /** Invoices issued by the context (sales). */
    case Seller = 'Subject1';

    /** Invoices received by the context (purchases). */
    case Buyer = 'Subject2';

    case ThirdParty = 'Subject3';
    case AuthorizedEntity = 'SubjectAuthorized';
}
