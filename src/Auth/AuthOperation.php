<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/** A started (asynchronous) authentication operation. */
final readonly class AuthOperation
{
    public function __construct(
        public string $referenceNumber,
        public TokenInfo $authenticationToken,
    ) {}
}
