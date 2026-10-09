<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/** Entity-level authorisations ("uprawnienia podmiotowe") one company can give another. */
enum EntityAuthorizationType: string
{
    /** The other entity may issue invoices on your behalf as the buyer (self-billing). */
    case SelfInvoicing = 'SelfInvoicing';
    /** The other entity may issue RR (flat-rate farmer) invoices for you. */
    case RrInvoicing = 'RRInvoicing';
    /** The other entity acts as your tax representative. */
    case TaxRepresentative = 'TaxRepresentative';
    /** The other entity may issue invoices in the Peppol network for you. */
    case PefInvoicing = 'PefInvoicing';
}
