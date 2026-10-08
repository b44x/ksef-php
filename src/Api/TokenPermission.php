<?php

declare(strict_types=1);

namespace Ksef\Api;

/** Permissions that can be granted to a KSeF token. Values are the API names. */
enum TokenPermission: string
{
    case InvoiceRead = 'InvoiceRead';
    case InvoiceWrite = 'InvoiceWrite';
    case CredentialsRead = 'CredentialsRead';
    case CredentialsManage = 'CredentialsManage';
    case SubunitManage = 'SubunitManage';
    case EnforcementOperations = 'EnforcementOperations';
    case Introspection = 'Introspection';
    case CollectiveIdentifierManage = 'CollectiveIdentifierManage';
}
