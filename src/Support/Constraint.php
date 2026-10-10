<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\ValidationException;

/**
 * Request limits that KSeF enforces (taken from its OpenAPI specification) and refuses with a 400 otherwise. Checking
 * them locally gives a precise message and saves the round trip.
 *
 * @internal
 */
final class Constraint
{
    public static function pageSize(int $pageSize, int $min, int $max): void
    {
        if ($pageSize < $min || $pageSize > $max) {
            throw new ValidationException(\sprintf('The page size must be between %d and %d, %d given.', $min, $max, $pageSize));
        }
    }

    public static function pageOffset(int $pageOffset): void
    {
        if ($pageOffset < 0) {
            throw new ValidationException('The page offset must not be negative.');
        }
    }

    public static function length(string $label, string $value, int $min, int $max): void
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min || $length > $max) {
            throw new ValidationException(\sprintf('The %s must have %d to %d characters, "%s" has %d.', $label, $min, $max, $value, $length));
        }
    }

    public static function pattern(string $label, string $value, string $pattern): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new ValidationException(\sprintf('The %s "%s" has an invalid format.', $label, $value));
        }
    }
}
