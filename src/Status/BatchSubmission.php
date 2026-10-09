<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

/**
 * A batch session whose parts were uploaded and which was closed: KSeF now processes it asynchronously.
 * Wait for the outcome with `KsefClient::waitForSession()` and list per-invoice results with
 * `KsefClient::sessionInvoices()`; correlate them to your documents via `$invoiceHashes`.
 */
final readonly class BatchSubmission
{
    /**
     * @param list<string> $invoiceHashes Base64 SHA-256 of each submitted invoice XML, in submission order
     */
    public function __construct(
        public string $sessionReference,
        public array $invoiceHashes,
    ) {}
}
