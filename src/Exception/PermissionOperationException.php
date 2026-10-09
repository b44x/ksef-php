<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

use B4x\Ksef\Permissions\OperationStatus;

/** KSeF finished a permission operation (grant or revoke) with a failure status. */
final class PermissionOperationException extends KsefException
{
    public function __construct(public readonly OperationStatus $status)
    {
        parent::__construct(\sprintf('The permission operation failed (%d): %s %s', $status->code, $status->description, implode('; ', $status->details)));
    }
}
