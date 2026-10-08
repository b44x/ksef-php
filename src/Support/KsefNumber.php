<?php

declare(strict_types=1);

namespace Ksef\Support;

use Ksef\Exception\ValidationException;

/**
 * KSeF invoice number: `NIP-YYYYMMDD-XXXXXXXXXXXX-CC` (35 characters), where `CC` is a CRC-8
 * (polynomial 0x07, initial value 0x00) of the first 32 characters.
 */
final readonly class KsefNumber
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $error = self::validate($value);
        if ($error !== null) {
            throw new ValidationException(\sprintf('"%s" is not a valid KSeF number: %s', $value, $error), [$error]);
        }

        return new self($value);
    }

    /** @return string|null a reason when the number is invalid */
    public static function validate(string $value): ?string
    {
        if (\strlen($value) !== 35) {
            return 'it must have exactly 35 characters';
        }
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-(20[2-9]\d|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-[0-9A-F]{12}-[0-9A-F]{2}$/', $value) !== 1) {
            return 'it does not match the expected structure';
        }

        return self::crc8(substr($value, 0, 32)) === substr($value, 33) ? null : 'the checksum does not match';
    }

    public static function crc8(string $data): string
    {
        $crc = 0;
        foreach (str_split($data) as $char) {
            $crc ^= \ord($char);
            for ($bit = 0; $bit < 8; ++$bit) {
                $crc = ($crc & 0x80) !== 0 ? (($crc << 1) ^ 0x07) & 0xFF : ($crc << 1) & 0xFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 2, '0', STR_PAD_LEFT));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
