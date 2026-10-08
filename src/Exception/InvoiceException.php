<?php

declare(strict_types=1);

namespace Ksef\Exception;

use Ksef\Status\InvoiceStatus;
use Throwable;

/** KSeF rejected an invoice or it could not be processed. */
class InvoiceException extends KsefException
{
    public function __construct(string $message, public readonly ?InvoiceStatus $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
