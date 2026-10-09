<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use SensitiveParameter;

/** A certificate signing request and the private key that belongs to it. Keep the key secret. */
final readonly class GeneratedCsr
{
    public function __construct(
        /** PKCS#10 request, DER, Base64 encoded, as the API expects. */
        public string $csrBase64,
        #[SensitiveParameter]
        public string $privateKeyPem,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['csrBase64' => $this->csrBase64, 'privateKeyPem' => '***'];
    }
}
