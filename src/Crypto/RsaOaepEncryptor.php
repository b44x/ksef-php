<?php

declare(strict_types=1);

namespace Ksef\Crypto;

use Ksef\Exception\EncryptionException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use SensitiveParameter;
use Throwable;

/**
 * RSAES-OAEP with SHA-256 as hash and MGF1 function, as required by KSeF.
 *
 * PHP's built-in openssl_public_encrypt() only supports OAEP with SHA-1, therefore the audited
 * phpseclib implementation is used for the padding.
 */
final class RsaOaepEncryptor
{
    public function encrypt(#[SensitiveParameter] string $plaintext, PublicKeyCertificate $certificate): string
    {
        try {
            $key = PublicKeyLoader::load($certificate->publicKeyPem());
            if (!$key instanceof PublicKey) {
                throw new EncryptionException('The KSeF key is not an RSA public key.');
            }

            // phpseclib's fluent setters are untyped; every step is narrowed explicitly.
            $key = $this->publicKey($key->withPadding(RSA::ENCRYPTION_OAEP));
            $key = $this->publicKey($key->withHash('sha256'));
            $key = $this->publicKey($key->withMGFHash('sha256'));
            $encrypted = $key->encrypt($plaintext);
        } catch (EncryptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EncryptionException('RSA-OAEP encryption failed.', 0, $e);
        }

        if (!\is_string($encrypted) || $encrypted === '') {
            throw new EncryptionException('RSA-OAEP encryption produced no output.');
        }

        return $encrypted;
    }

    private function publicKey(mixed $key): PublicKey
    {
        return $key instanceof PublicKey ? $key : throw new EncryptionException('The KSeF key is not an RSA public key.');
    }
}
