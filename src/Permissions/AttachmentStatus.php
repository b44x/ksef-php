<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use DateTimeImmutable;

/** Whether the current context may issue invoices with attachments (consent given to the Ministry of Finance). */
final readonly class AttachmentStatus
{
    public function __construct(
        public bool $allowed,
        /** When the consent ends; null means it does not expire. */
        public ?DateTimeImmutable $revokedAt,
    ) {}
}
