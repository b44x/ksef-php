<?php

declare(strict_types=1);

namespace Ksef\Exception;

use Ksef\Status\InvoiceStatus;

/**
 * KSeF finished processing an invoice with a failure status. The invoice has no KSeF number.
 *
 * For duplicates (status 440) {@see InvoiceStatus::originalKsefNumber()} identifies the document
 * that is already stored, which usually means a previous submission actually succeeded.
 */
final class InvoiceRejectedException extends InvoiceException
{
    public static function fromStatus(InvoiceStatus $status, ?string $invoiceReference = null): self
    {
        $message = \sprintf('KSeF rejected the invoice (status %d): %s', $status->code, $status->description);
        if ($status->details !== []) {
            $message .= ' – ' . implode('; ', $status->details);
        }
        if ($status->originalKsefNumber() !== null) {
            $message .= \sprintf(' (already stored as %s)', $status->originalKsefNumber());
        }
        if ($invoiceReference !== null) {
            $message .= \sprintf(' [invoice reference %s]', $invoiceReference);
        }

        return new self($message, $status);
    }
}
