<?php

declare(strict_types=1);

namespace B4x\Ksef\Limits;

/** Size and count limits of a session type in the current context. */
final readonly class SessionLimits
{
    public function __construct(
        public int $maxInvoices,
        public int $maxInvoiceSizeMb,
        public int $maxInvoiceWithAttachmentSizeMb,
    ) {}
}
