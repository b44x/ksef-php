<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/** Permissions that can be granted to a person to work in a KSeF context. Values are the API names. */
enum Permission: string
{
    /** Issue invoices. */
    case InvoiceWrite = 'InvoiceWrite';

    /** View invoices. */
    case InvoiceRead = 'InvoiceRead';

    /** Manage permissions. */
    case CredentialsManage = 'CredentialsManage';

    /** View permissions. */
    case CredentialsRead = 'CredentialsRead';

    /** View session history and generate UPOs. */
    case Introspection = 'Introspection';

    /** Manage subordinate units. */
    case SubunitManage = 'SubunitManage';

    /** Enforcement operations (only in enforcement authority / bailiff contexts). */
    case EnforcementOperations = 'EnforcementOperations';

    case CollectiveIdentifierManage = 'CollectiveIdentifierManage';
}
