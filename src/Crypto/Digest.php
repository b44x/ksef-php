<?php

declare(strict_types=1);

namespace B4x\Ksef\Crypto;

/** SHA-256 helpers in the encodings KSeF expects. */
final class Digest
{
    private function __construct() {}

    /** SHA-256 of the data, Base64 encoded (used for invoice and encrypted invoice hashes). */
    public static function sha256Base64(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }

    /** SHA-256 of the data as lower-case hexadecimal (certificate fingerprints). */
    public static function sha256Hex(string $data): string
    {
        return hash('sha256', $data);
    }
}
