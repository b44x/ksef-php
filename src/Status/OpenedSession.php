<?php

declare(strict_types=1);

namespace Ksef\Status;

use DateTimeImmutable;

/** Result of opening an online session. */
final readonly class OpenedSession
{
    public function __construct(
        public string $referenceNumber,
        public DateTimeImmutable $validUntil,
    ) {}
}
