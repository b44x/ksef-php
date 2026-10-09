<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use B4x\Ksef\Http\Payload;

/**
 * Processing status of one invoice inside a session.
 *
 * Codes: 100 accepted for processing, 150 processing, 200 success, 405 cancelled because of a
 * session error, 410 invalid permissions, 415 attachments not allowed, 430 invoice file
 * verification error, 435 decryption error, 440 duplicate, 450 semantic validation error,
 * 500 unknown error, 550 cancelled by the system. Anything below 400 other than 200 is "still running".
 */
final readonly class InvoiceStatus
{
    /**
     * @param list<string> $details
     * @param array<string, mixed> $extensions e.g. originalKsefNumber for duplicates
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details = [],
        public array $extensions = [],
    ) {}

    public static function fromPayload(Payload $status): self
    {
        return new self($status->int('code'), $status->string('description'), $status->strings('details'), $status->map('extensions'));
    }

    /** The invoice is accepted and has been assigned a KSeF number. */
    public function isAccepted(): bool
    {
        return $this->code === 200;
    }

    /** Still being verified; keep polling. */
    public function isProcessing(): bool
    {
        return $this->code < 200;
    }

    /** Terminal failure: the invoice was not accepted. */
    public function isRejected(): bool
    {
        return $this->code >= 400;
    }

    public function isTerminal(): bool
    {
        return !$this->isProcessing();
    }

    /** True when KSeF recognised the invoice as already submitted (seller NIP, type and number match). */
    public function isDuplicate(): bool
    {
        return $this->code === 440;
    }

    /** KSeF number of the invoice that this one duplicates, if KSeF reported it. */
    public function originalKsefNumber(): ?string
    {
        $value = $this->extensions['originalKsefNumber'] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function originalSessionReference(): ?string
    {
        $value = $this->extensions['originalSessionReferenceNumber'] ?? null;

        return \is_string($value) ? $value : null;
    }
}
