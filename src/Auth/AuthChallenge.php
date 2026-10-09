<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use DateTimeImmutable;

/** Response of POST /auth/challenge. A challenge is valid for 10 minutes and can be used once. */
final readonly class AuthChallenge
{
    public function __construct(
        public string $challenge,
        public DateTimeImmutable $timestamp,
        public int $timestampMs,
    ) {}
}
