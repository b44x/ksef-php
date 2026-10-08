<?php

declare(strict_types=1);

namespace Ksef\Auth;

/**
 * Status of an authentication operation.
 *
 * Codes: 100 in progress, 200 succeeded, 415 no permissions in the context, 425 revoked,
 * 450 invalid KSeF token, 460 certificate problem, 470 failed, 480 blocked (suspected security
 * incident), 500 unknown error, 550 cancelled by the system (retry).
 */
final readonly class AuthStatus
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details = [],
        public ?string $method = null,
    ) {}

    public function isInProgress(): bool
    {
        return $this->code === 100;
    }

    public function isSuccessful(): bool
    {
        return $this->code === 200;
    }

    public function isFailure(): bool
    {
        return !$this->isInProgress() && !$this->isSuccessful();
    }
}
