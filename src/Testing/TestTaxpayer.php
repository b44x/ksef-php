<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Support\Nip;
use SensitiveParameter;

/**
 * A throw-away taxpayer on the KSeF TEST environment together with a self-signed certificate that logs in
 * as its owner. Created by {@see TestEnvironment::createTaxpayer()}. TEST only: self-signed certificates are
 * refused on DEMO and production.
 */
final readonly class TestTaxpayer
{
    public function __construct(
        public Nip $nip,
        public string $pesel,
        public string $certificatePem,
        #[SensitiveParameter]
        public string $privateKeyPem,
    ) {}

    public function context(): ContextIdentifier
    {
        return ContextIdentifier::nip($this->nip->value);
    }

    public function credentials(): CertificateCredentials
    {
        return CertificateCredentials::fromPem($this->certificatePem, $this->privateKeyPem);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['nip' => $this->nip->value, 'pesel' => $this->pesel, 'privateKeyPem' => '***'];
    }
}
