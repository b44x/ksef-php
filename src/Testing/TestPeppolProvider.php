<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use SensitiveParameter;

/**
 * A Peppol service provider identity for the KSeF TEST environment, created by
 * {@see TestEnvironment::createPeppolProvider()}. Companies authorise it with
 * `grantAuthorization($provider->id, EntityAuthorizationType::PefInvoicing, ...)`; the provider then signs in with
 * {@see self::context()} and sends PEF invoices on their behalf.
 */
final readonly class TestPeppolProvider
{
    public function __construct(
        public string $id,
        public string $certificatePem,
        #[SensitiveParameter]
        public string $privateKeyPem,
    ) {}

    public function context(): ContextIdentifier
    {
        return ContextIdentifier::peppolId($this->id);
    }

    public function credentials(): CertificateCredentials
    {
        return CertificateCredentials::fromPem($this->certificatePem, $this->privateKeyPem);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'privateKeyPem' => '***'];
    }
}
