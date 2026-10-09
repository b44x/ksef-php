<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

/** Processing state of a certificate request: 100 in progress, 200 issued, 400 rejected, 500/550 failure. */
final readonly class EnrollmentStatus
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details,
        public ?string $certificateSerialNumber,
    ) {}

    public function isInProgress(): bool
    {
        return $this->code === 100;
    }

    public function isIssued(): bool
    {
        return $this->code === 200 && $this->certificateSerialNumber !== null;
    }
}
