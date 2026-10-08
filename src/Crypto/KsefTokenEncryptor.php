<?php

declare(strict_types=1);

namespace Ksef\Crypto;

use SensitiveParameter;

/** Builds the `token|timestampMs` secret for token authentication and encrypts it. */
final class KsefTokenEncryptor
{
    public function __construct(
        private readonly PublicKeyProvider $keys,
        private readonly RsaOaepEncryptor $rsa = new RsaOaepEncryptor(),
    ) {}

    /**
     * @param int $challengeTimestampMs timestampMs from POST /auth/challenge
     *
     * @return array{encryptedToken: string, publicKeyId: string} Base64 ciphertext and the id of the key used
     */
    public function encrypt(#[SensitiveParameter] string $ksefToken, int $challengeTimestampMs): array
    {
        $certificate = $this->keys->get(KeyUsage::KsefTokenEncryption);

        return [
            'encryptedToken' => base64_encode($this->rsa->encrypt($ksefToken . '|' . $challengeTimestampMs, $certificate)),
            'publicKeyId' => $certificate->publicKeyId,
        ];
    }
}
