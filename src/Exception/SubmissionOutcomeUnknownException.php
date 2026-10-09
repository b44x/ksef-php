<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

use Throwable;

/**
 * An invoice submission failed in a way that does not tell whether KSeF accepted the document.
 *
 * This is NOT a rejection. Do not blindly generate a new invoice number. Either re-send the very
 * same document (KSeF detects duplicates by seller NIP, invoice type and invoice number and answers
 * with status 440) or reconcile via the session's invoice list using {@see self::$invoiceHash}.
 */
final class SubmissionOutcomeUnknownException extends TransportException
{
    public function __construct(
        string $message,
        public readonly string $sessionReference,
        public readonly string $invoiceHash,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
