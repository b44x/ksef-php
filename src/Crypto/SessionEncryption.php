<?php

declare(strict_types=1);

namespace Ksef\Crypto;

use Ksef\Exception\EncryptionException;
use SensitiveParameter;

/**
 * Per-session symmetric encryption: AES-256-CBC with PKCS#7 padding.
 *
 * A fresh random key and IV are generated for every session (as KSeF recommends). The key is
 * wrapped with the Ministry's RSA key via {@see self::encryptionInfo()} when the session is opened.
 */
final class SessionEncryption
{
    private const CIPHER = 'aes-256-cbc';

    private function __construct(
        #[SensitiveParameter]
        private readonly string $key,
        private readonly string $iv,
    ) {
        if (\strlen($key) !== 32 || \strlen($iv) !== 16) {
            throw new EncryptionException('AES-256-CBC requires a 32 byte key and a 16 byte initialization vector.');
        }
    }

    public static function generate(): self
    {
        return new self(random_bytes(32), random_bytes(16));
    }

    /** Restores a context from known material (tests, resuming a session you opened earlier). */
    public static function fromMaterial(#[SensitiveParameter] string $key, string $iv): self
    {
        return new self($key, $iv);
    }

    public function encrypt(string $plaintext): string
    {
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($encrypted === false) {
            throw new EncryptionException('AES-256-CBC encryption failed.');
        }

        return $encrypted;
    }

    public function decrypt(string $ciphertext): string
    {
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($decrypted === false) {
            throw new EncryptionException('AES-256-CBC decryption failed.');
        }

        return $decrypted;
    }

    /**
     * The `encryption` object of the "open session" request.
     *
     * @return array{encryptedSymmetricKey: string, initializationVector: string, publicKeyId: string}
     */
    public function encryptionInfo(PublicKeyProvider $keys, RsaOaepEncryptor $rsa = new RsaOaepEncryptor()): array
    {
        $certificate = $keys->get(KeyUsage::SymmetricKeyEncryption);

        return [
            'encryptedSymmetricKey' => base64_encode($rsa->encrypt($this->key, $certificate)),
            'initializationVector' => base64_encode($this->iv),
            'publicKeyId' => $certificate->publicKeyId,
        ];
    }

    /** @return array<string, string> never reveals the key material */
    public function __debugInfo(): array
    {
        return ['key' => '***', 'iv' => '***'];
    }
}
