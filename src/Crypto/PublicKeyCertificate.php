<?php

declare(strict_types=1);

namespace B4x\Ksef\Crypto;

use B4x\Ksef\Exception\EncryptionException;
use DateTimeImmutable;
use Exception;

/** An X.509 certificate published by KSeF that carries a public key used for client-side encryption. */
final readonly class PublicKeyCertificate
{
    /**
     * @param string $certificateDer DER encoded certificate (binary)
     * @param string $publicKeyId Base64 SHA-256 of the SubjectPublicKeyInfo; sent to KSeF as selector
     * @param list<KeyUsage> $usages
     */
    public function __construct(
        public string $certificateDer,
        public string $publicKeyId,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validTo,
        public array $usages,
    ) {}

    /**
     * @param array<string, mixed> $data one element of the endpoint response
     */
    public static function fromApi(array $data): self
    {
        $certificate = $data['certificate'] ?? null;
        $publicKeyId = $data['publicKeyId'] ?? null;
        $validFrom = $data['validFrom'] ?? null;
        $validTo = $data['validTo'] ?? null;
        $usage = $data['usage'] ?? null;

        if (!\is_string($certificate) || !\is_string($publicKeyId) || !\is_string($validFrom) || !\is_string($validTo) || !\is_array($usage)) {
            throw new EncryptionException('The published public key certificate has an unexpected structure.');
        }

        $der = base64_decode($certificate, true);
        if ($der === false || $der === '') {
            throw new EncryptionException('The published public key certificate is not valid Base64.');
        }

        $usages = [];
        foreach ($usage as $value) {
            $parsed = \is_string($value) ? KeyUsage::tryFrom($value) : null;
            if ($parsed !== null) {
                $usages[] = $parsed;
            }
        }

        try {
            $certificateModel = new self($der, $publicKeyId, new DateTimeImmutable($validFrom), new DateTimeImmutable($validTo), $usages);
        } catch (Exception $e) {
            throw new EncryptionException('The published public key certificate has invalid validity dates.', 0, $e);
        }
        $certificateModel->assertConsistent();

        return $certificateModel;
    }

    public function supports(KeyUsage $usage): bool
    {
        return \in_array($usage, $this->usages, true);
    }

    public function isValidAt(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->validFrom && $moment <= $this->validTo;
    }

    /**
     * Base64 SHA-256 of the DER SubjectPublicKeyInfo of the certificate: what KSeF calls `publicKeyId`.
     */
    public function computedPublicKeyId(): string
    {
        $key = openssl_pkey_get_public($this->certificatePem());
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key']) || !\is_string($details['key'])) {
            throw new EncryptionException('The KSeF public key certificate cannot be parsed.');
        }
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $details['key']);
        $spki = \is_string($body) ? base64_decode($body, true) : false;
        if ($spki === false || $spki === '') {
            throw new EncryptionException('The public key cannot be extracted from the KSeF certificate.');
        }

        return base64_encode(hash('sha256', $spki, true));
    }

    /**
     * The selector sent back to KSeF must be the hash of the key that is actually used for encryption; a listing in
     * which the two disagree is refused instead of encrypting for an unexpected key.
     */
    private function assertConsistent(): void
    {
        if (!hash_equals($this->computedPublicKeyId(), $this->publicKeyId)) {
            throw new EncryptionException('A published KSeF public key certificate does not match its publicKeyId; refusing to use it.');
        }
    }

    private function certificatePem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($this->certificateDer), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    /** PEM encoded SubjectPublicKeyInfo extracted from the certificate. */
    public function publicKeyPem(): string
    {
        $key = openssl_pkey_get_public($this->certificatePem());
        if ($key === false) {
            throw new EncryptionException('The KSeF public key certificate cannot be parsed.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key']) || !\is_string($details['key'])) {
            throw new EncryptionException('The public key cannot be extracted from the KSeF certificate.');
        }
        $bits = $details['bits'] ?? null;
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !\is_int($bits) || $bits < 2048) {
            throw new EncryptionException('Only RSA keys of at least 2048 bits are supported for KSeF encryption.');
        }

        return $details['key'];
    }
}
