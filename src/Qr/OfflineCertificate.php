<?php

declare(strict_types=1);

namespace B4x\Ksef\Qr;

use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Support\EcdsaSignature;
use OpenSSLAsymmetricKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use SensitiveParameter;

/**
 * A KSeF certificate of type *Offline* together with its private key; signs KOD II links.
 * (Certificates of type *Authentication* must not be used for this.)
 *
 * RSA keys sign with RSASSA-PSS (SHA-256, MGF1 SHA-256, 32 byte salt); EC P-256 keys with
 * ECDSA/SHA-256 in the recommended fixed-width IEEE P1363 (`R||S`) encoding.
 */
final class OfflineCertificate
{
    private readonly OpenSSLAsymmetricKey $key;
    private readonly bool $isEc;
    private readonly string $serialHex;
    private readonly string $privateKeyPem;
    private readonly ?string $passphrase;

    public function __construct(
        string $certificatePem,
        #[SensitiveParameter]
        string $privateKeyPem,
        #[SensitiveParameter]
        ?string $passphrase = null,
    ) {
        $certificate = openssl_x509_read($certificatePem);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
        if ($certificate === false || $parsed === false || !\is_string($parsed['serialNumberHex'] ?? null)) {
            throw new SigningException('The offline certificate cannot be parsed.');
        }

        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false || !openssl_x509_check_private_key($certificate, $key)) {
            throw new SigningException('The private key is unusable or does not match the offline certificate.');
        }

        $details = openssl_pkey_get_details($key);
        $type = \is_array($details) ? ($details['type'] ?? null) : null;
        $bits = \is_array($details) ? ($details['bits'] ?? 0) : 0;
        if ($type === OPENSSL_KEYTYPE_RSA && \is_int($bits) && $bits >= 2048) {
            $this->isEc = false;
        } elseif ($type === OPENSSL_KEYTYPE_EC && \is_array($details) && \is_array($details['ec'] ?? null) && ($details['ec']['curve_name'] ?? null) === 'prime256v1') {
            $this->isEc = true;
        } else {
            throw new SigningException('Only RSA keys of at least 2048 bits and EC P-256 (prime256v1) keys are supported.');
        }

        $this->key = $key;
        $this->serialHex = strtoupper($parsed['serialNumberHex']);
        $this->privateKeyPem = $privateKeyPem;
        $this->passphrase = $passphrase;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['serialNumber' => $this->serialHex, 'privateKeyPem' => '***', 'passphrase' => '***'];
    }

    /** Certificate serial number as upper-case hexadecimal, the form used in KOD II links. */
    public function serialNumber(): string
    {
        return $this->serialHex;
    }

    /** Signature over the data, Base64URL encoded without padding. */
    public function signBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($this->isEc ? $this->signEc($data) : $this->signRsaPss($data)), '+/', '-_'), '=');
    }

    private function signRsaPss(string $data): string
    {
        $key = PublicKeyLoader::load($this->privateKeyPem, $this->passphrase ?? '');
        if (!$key instanceof PrivateKey) {
            throw new SigningException('The offline certificate key is not an RSA private key.');
        }
        // phpseclib's fluent setters are untyped; narrow every step.
        $configured = $key->withPadding(RSA::SIGNATURE_PSS);
        $configured = $configured instanceof PrivateKey ? $configured->withHash('sha256') : null;
        $configured = $configured instanceof PrivateKey ? $configured->withMGFHash('sha256') : null;
        $configured = $configured instanceof PrivateKey ? $configured->withSaltLength(32) : null;
        if (!$configured instanceof PrivateKey) {
            throw new SigningException('Cannot configure RSASSA-PSS.');
        }

        $signature = $configured->sign($data);

        return \is_string($signature) && $signature !== '' ? $signature : throw new SigningException('RSA-PSS signing failed.');
    }

    private function signEc(string $data): string
    {
        $der = '';
        if (!openssl_sign($data, $der, $this->key, OPENSSL_ALGO_SHA256) || !\is_string($der) || $der === '') {
            throw new SigningException('ECDSA signing failed.');
        }

        return EcdsaSignature::derToFixed($der, 32);
    }
}
