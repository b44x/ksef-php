<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\ValidationException;

/** Polish tax identification number (NIP): ten digits with a modulo-11 check digit. */
final readonly class Nip
{
    private const WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    private function __construct(public string $value) {}

    /**
     * Parses a NIP, tolerating the usual separators ("526-587-76-35", "526 587 76 35", "PL5265877635").
     *
     * KSeF verifies the check digit on the production environment only, so an invalid checksum is
     * rejected here by default to catch typos before they reach the API.
     */
    public static function of(string $value, bool $verifyChecksum = true): self
    {
        $digits = preg_replace('/[\s-]|^PL/i', '', $value) ?? '';

        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $digits) !== 1) {
            throw new ValidationException(\sprintf('"%s" is not a valid NIP format.', $value), [\sprintf('Invalid NIP: %s', $value)]);
        }
        if ($verifyChecksum && !self::hasValidChecksum($digits)) {
            throw new ValidationException(\sprintf('"%s" has an invalid NIP check digit.', $value), [\sprintf('Invalid NIP check digit: %s', $value)]);
        }

        return new self($digits);
    }

    /**
     * For the KSeF TEST environment, whose fabricated NIPs frequently fail the checksum.
     * Never use it with production data.
     */
    public static function unchecked(string $value): self
    {
        return self::of($value, false);
    }

    public static function hasValidChecksum(string $digits): bool
    {
        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        return $sum % 11 === (int) $digits[9];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
