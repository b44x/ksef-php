<?php

declare(strict_types=1);

namespace B4x\Ksef\Limits;

/** Limits that apply to the current subject (the taxpayer): certificate enrolments and certificates. A null value means KSeF reports no limit. */
final readonly class SubjectLimits
{
    public function __construct(
        public ?int $maxEnrollments,
        public ?int $maxCertificates,
    ) {}
}
