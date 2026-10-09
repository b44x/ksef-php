<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

use SensitiveParameter;

/**
 * A freshly generated KSeF token. KSeF reveals the secret exactly once, so store it immediately
 * in a secret manager. The token becomes usable when its status turns to Active.
 */
final readonly class GeneratedToken
{
    public function __construct(
        public string $referenceNumber,
        #[SensitiveParameter]
        public string $token,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['referenceNumber' => $this->referenceNumber, 'token' => '***'];
    }
}
