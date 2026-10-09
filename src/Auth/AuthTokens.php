<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/** The access/refresh token pair obtained after a successful authentication. */
final readonly class AuthTokens
{
    public function __construct(
        public TokenInfo $accessToken,
        public TokenInfo $refreshToken,
    ) {}
}
