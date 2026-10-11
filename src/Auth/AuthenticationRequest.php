<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use DateTimeImmutable;

/**
 * An `AuthTokenRequest` waiting for a signature (see {@see ManualCredentials}).
 *
 * Sign {@see self::$xml} with an enveloped XAdES signature (the signature becomes the last child of the root) and
 * pass the result to `KsefClient::authenticateWithSignedRequest()` before {@see self::$expiresAt}.
 */
final readonly class AuthenticationRequest
{
    public function __construct(
        /** The document to sign (UTF-8). */
        public string $xml,
        public string $challenge,
        public DateTimeImmutable $createdAt,
        /** KSeF accepts the challenge for 10 minutes and only once. */
        public DateTimeImmutable $expiresAt,
    ) {}
}
