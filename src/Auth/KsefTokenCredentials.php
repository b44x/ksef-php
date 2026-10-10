<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\ValidationException;
use JsonSerializable;
use SensitiveParameter;

/**
 * A KSeF token generated in the KSeF application or through POST /tokens.
 *
 * Treat the value like a password: load it from a secret store, never commit it, never log it.
 */
final readonly class KsefTokenCredentials implements JsonSerializable, Credentials
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
    ) {
        if (trim($token) === '') {
            throw new ValidationException('The KSeF token must not be empty.');
        }
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['token' => '***'];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '***'];
    }
}
