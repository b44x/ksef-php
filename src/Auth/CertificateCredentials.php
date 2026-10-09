<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Signing\OpenSslXadesSigner;
use B4x\Ksef\Signing\XadesSigner;
use SensitiveParameter;

/**
 * Authentication with a certificate: a qualified signature or seal, a KSeF certificate, or (TEST
 * environment only) a self-signed certificate. The AuthTokenRequest is signed with XAdES.
 */
final readonly class CertificateCredentials implements Credentials
{
    public function __construct(
        public XadesSigner $signer,
        public SubjectIdentifierType $identifiedBy = SubjectIdentifierType::CertificateSubject,
    ) {}

    public static function fromPem(
        string $certificatePem,
        #[SensitiveParameter]
        string $privateKeyPem,
        #[SensitiveParameter]
        ?string $passphrase = null,
        SubjectIdentifierType $identifiedBy = SubjectIdentifierType::CertificateSubject,
    ): self {
        return new self(new OpenSslXadesSigner($certificatePem, $privateKeyPem, $passphrase), $identifiedBy);
    }

    public static function fromPemFiles(
        string $certificatePath,
        string $privateKeyPath,
        #[SensitiveParameter]
        ?string $passphrase = null,
        SubjectIdentifierType $identifiedBy = SubjectIdentifierType::CertificateSubject,
    ): self {
        return self::fromPem(self::read($certificatePath), self::read($privateKeyPath), $passphrase, $identifiedBy);
    }

    public static function fromPkcs12(
        #[SensitiveParameter]
        string $pkcs12,
        #[SensitiveParameter]
        string $password,
        SubjectIdentifierType $identifiedBy = SubjectIdentifierType::CertificateSubject,
    ): self {
        $parts = [];
        if (!openssl_pkcs12_read($pkcs12, $parts, $password)) {
            throw new ConfigurationException('The PKCS#12 bundle cannot be opened (wrong password or unsupported encryption).');
        }

        $bundle = \is_array($parts) ? $parts : [];
        $certificate = $bundle['cert'] ?? null;
        $privateKey = $bundle['pkey'] ?? null;
        if (!\is_string($certificate) || !\is_string($privateKey)) {
            throw new ConfigurationException('The PKCS#12 bundle lacks a certificate or a private key.');
        }

        return self::fromPem($certificate, $privateKey, null, $identifiedBy);
    }

    private static function read(string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return $contents !== false ? $contents : throw new ConfigurationException(\sprintf('Cannot read "%s".', $path));
    }
}
