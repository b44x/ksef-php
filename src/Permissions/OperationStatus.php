<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

/**
 * State of an asynchronous permission operation: 100 accepted, 200 succeeded, 400 failed, 410 identifiers
 * mismatched, 420 credentials lack the right to do this, 430 wrong context role, 440 not allowed for these
 * relations, 450 not allowed for this identifier type, 500 unknown error, 550 cancelled by the system (retry).
 */
final readonly class OperationStatus
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details,
    ) {}

    public function isInProgress(): bool
    {
        return $this->code === 100;
    }

    public function isSuccessful(): bool
    {
        return $this->code === 200;
    }
}
