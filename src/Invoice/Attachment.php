<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * The structured attachment of an invoice (`Zalacznik`), for example a detailed statement of services.
 *
 * KSeF only accepts invoices with an attachment from taxpayers who notified the Ministry of Finance beforehand
 * (see `KsefClient::attachmentStatus()`), and only in batch sessions: send them with `KsefClient::sendBatch()`.
 * Invoices with an attachment may be larger (up to 3 MB instead of 1 MB).
 */
final readonly class Attachment
{
    /**
     * @param non-empty-list<AttachmentBlock> $blocks up to 1000
     */
    public function __construct(public array $blocks) {}
}
