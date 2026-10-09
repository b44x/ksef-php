<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

/** How many certificate requests and active certificates the authenticated subject may still have. */
final readonly class CertificateLimits
{
    public function __construct(
        public bool $canRequest,
        public int $enrollmentLimit,
        public int $enrollmentRemaining,
        public int $certificateLimit,
        public int $certificateRemaining,
    ) {}
}
