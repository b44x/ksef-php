<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/** Kind of identifier that selects the authentication context. */
enum ContextIdentifierType: string
{
    case Nip = 'Nip';
    case InternalId = 'InternalId';
    case NipVatUe = 'NipVatUe';
    case PeppolId = 'PeppolId';
}
