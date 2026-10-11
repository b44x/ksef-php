<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\SigningException;

/**
 * Conversion between ASN.1 DER ECDSA signatures and fixed-width `R||S` (IEEE P1363).
 *
 * @internal
 */
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

    /** Converts the fixed-width `R||S` form used by XML-DSig back to the DER structure OpenSSL verifies. */
    public static function fixedToDer(string $fixed): string
    {
        $half = intdiv(\strlen($fixed), 2);
        $encode = static function (string $integer): string {
            $integer = ltrim($integer, "\x00");
            if ($integer === '' || (\ord($integer[0]) & 0x80) !== 0) {
                $integer = "\x00" . $integer;
            }

            return "\x02" . \chr(\strlen($integer)) . $integer;
        };
        $body = $encode(substr($fixed, 0, $half)) . $encode(substr($fixed, $half));

        return "\x30" . \chr(\strlen($body)) . $body;
    }
}
