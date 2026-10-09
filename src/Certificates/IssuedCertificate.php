<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Qr\OfflineCertificate;
use SensitiveParameter;

/**
 * A KSeF certificate with the private key generated for it. Persist both in a secret store:
 * the key exists nowhere else and cannot be recovered.
 */
final readonly class IssuedCertificate
{
    public function __construct(
        public string $serialNumber,
        public string $name,
        public CertificateType $type,
        public string $certificatePem,
        #[SensitiveParameter]
        public string $privateKeyPem,
    ) {}

    /** Credentials for authenticating with this certificate (type Authentication only). */
    public function toCredentials(): CertificateCredentials
    {
        if ($this->type !== CertificateType::Authentication) {
            throw new SigningException('Only Authentication certificates can authenticate.');
        }

        return CertificateCredentials::fromPem($this->certificatePem, $this->privateKeyPem);
    }

    /** Signer for QR code II links (type Offline only). */
    public function toOfflineCertificate(): OfflineCertificate
    {
        if ($this->type !== CertificateType::Offline) {
            throw new SigningException('Only Offline certificates can sign QR code II links.');
        }

        return new OfflineCertificate($this->certificatePem, $this->privateKeyPem);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['serialNumber' => $this->serialNumber, 'name' => $this->name, 'type' => $this->type->value, 'privateKeyPem' => '***'];
    }
}
