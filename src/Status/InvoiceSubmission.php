<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

/**
 * Proof that KSeF *accepted a document for processing* (HTTP 202). This is not an approval.
 *
 * Processing is asynchronous: ask for the final result with the client's `waitForInvoice()`
 * or `invoiceStatus()` methods. The submission carries everything needed to do so later, even
 * from another process, so it is safe to persist `sessionReference`, `invoiceReference`
 * and `invoiceHash`.
 */
final readonly class InvoiceSubmission
{
    public function __construct(
        public string $sessionReference,
        public string $invoiceReference,
        public string $invoiceHash,
        /** True when the SDK had to look the document up or re-send it after an ambiguous failure. Use `assertStored()` on the result. */
        public bool $recovered = false,
    ) {}

    public function markRecovered(): self
    {
        return new self($this->sessionReference, $this->invoiceReference, $this->invoiceHash, true);
    }
}
