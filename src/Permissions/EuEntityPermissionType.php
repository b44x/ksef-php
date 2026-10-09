<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/** Permissions a representative of an EU entity can receive. */
enum EuEntityPermissionType: string
{
    case InvoiceWrite = 'InvoiceWrite';
    case InvoiceRead = 'InvoiceRead';
}
