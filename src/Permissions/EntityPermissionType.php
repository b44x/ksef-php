<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/** Invoice-handling permissions that can be granted to another entity (company). */
enum EntityPermissionType: string
{
    case InvoiceWrite = 'InvoiceWrite';
    case InvoiceRead = 'InvoiceRead';
    case CollectiveIdentifierManage = 'CollectiveIdentifierManage';
}
