<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

/** The current availability of KSeF with the messages that explain it. */
final readonly class AvailabilityReport
{
    /**
     * @param list<AvailabilityMessage> $messages newest first; empty when KSeF is available
     */
    public function __construct(
        public AvailabilityStatus $status,
        public array $messages = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === AvailabilityStatus::Available;
    }

    /**
     * True when KSeF is down for sending invoices (a failure, not a planned maintenance window): the moment to issue
     * invoices in offline mode (see docs/OFFLINE.md).
     */
    public function isFailure(): bool
    {
        return $this->status === AvailabilityStatus::Failure || $this->status === AvailabilityStatus::TotalFailure;
    }
}
