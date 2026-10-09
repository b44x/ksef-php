<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\SigningException;

/** Conversion between ASN.1 DER ECDSA signatures and fixed-width `R||S` (IEEE P1363). */
final class EcdsaSignature
{
    private function __construct() {}

    public static function derToFixed(string $der, int $fieldBytes): string
    {
        $offset = 2;
        if ((\ord($der[1]) & 0x80) !== 0) {
            $offset += \ord($der[1]) & 0x7F;
        }

        $parts = [];
        for ($i = 0; $i < 2; ++$i) {
            if (\ord($der[$offset]) !== 0x02) {
                throw new SigningException('Unexpected ECDSA signature encoding.');
            }
            $length = \ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $parts[] = str_pad(ltrim($integer, "\x00"), $fieldBytes, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }
}
