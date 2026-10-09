<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\ValidationException;

/**
 * Immutable fixed-point decimal number for monetary and quantity arithmetic.
 *
 * Floats are deliberately not accepted anywhere: they cannot represent most decimal fractions and
 * would silently produce wrong invoice totals. Values are held as an integer and a scale, so
 * results are exact; operations that would overflow a 64-bit integer throw instead of rounding.
 */
final readonly class Decimal
{
    private const MAX_SCALE = 18;

    private function __construct(
        private int $unscaled,
        private int $scale,
    ) {}

    /**
     * @param string|int $value for example "12.50", "-3", 7
     */
    public static function of(string|int $value): self
    {
        if (\is_int($value)) {
            return new self($value, 0);
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new ValidationException(\sprintf('"%s" is not a valid decimal number.', $value), [\sprintf('Invalid decimal: %s', $value)]);
        }

        $fraction = $m[3] ?? '';
        if (\strlen($fraction) > self::MAX_SCALE) {
            throw new ValidationException(\sprintf('"%s" has more than %d decimal places.', $value, self::MAX_SCALE));
        }

        $digits = ltrim($m[2] . $fraction, '0');
        if ($digits === '') {
            return new self(0, \strlen($fraction));
        }
        if (\strlen($digits) > 19 || (\strlen($digits) === 19 && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new ValidationException(\sprintf('"%s" is too large.', $value));
        }

        $unscaled = (int) $digits;

        return new self($m[1] === '-' ? -$unscaled : $unscaled, \strlen($fraction));
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function add(self $other): self
    {
        [$a, $b, $scale] = $this->align($other);
        return new self(self::checkedAdd($a, $b), $scale);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function multiply(self $other): self
    {
        $scale = $this->scale + $other->scale;
        if ($scale > self::MAX_SCALE) {
            // Reduce precision first; exactness beyond 18 places is never needed for invoices.
            $excess = $scale - self::MAX_SCALE;
            $reducible = min($excess, $this->scale);
            $left = $this->roundTo($this->scale - $reducible);
            $right = $other->roundTo($other->scale - ($excess - $reducible));

            return $left->multiply($right);
        }

        return new self(self::checkedMultiply($this->unscaled, $other->unscaled), $scale);
    }

    /**
     * Divides and rounds half away from zero to the given number of places.
     */
    public function dividedBy(self $divisor, int $places): self
    {
        if ($divisor->isZero()) {
            throw new ValidationException('Division by zero.');
        }
        if ($places < 0 || $places > self::MAX_SCALE) {
            throw new ValidationException('Division precision must be between 0 and ' . self::MAX_SCALE . '.');
        }

        // (a / 10^sa) / (b / 10^sb) = a * 10^(sb - sa) / b ; scale the numerator so the quotient has places + 1 digits to round on.
        $numerator = $this->unscaled;
        $exponent = $places + 1 + $divisor->scale - $this->scale;
        $denominator = $divisor->unscaled;
        if ($exponent >= 0) {
            $numerator = self::checkedMultiply($numerator, $this->pow10($exponent));
        } else {
            $denominator = self::checkedMultiply($denominator, $this->pow10(-$exponent));
        }

        $quotient = intdiv(abs($numerator), abs($denominator));
        $negative = ($numerator < 0) !== ($denominator < 0);

        return (new self($negative ? -$quotient : $quotient, $places + 1))->roundTo($places);
    }

    /** Multiplies by a percentage: `of('200')->percent(of('23'))` is 46. */
    public function percent(self $percentage): self
    {
        return $this->multiply($percentage)->shiftRight(2);
    }

    public function negate(): self
    {
        return new self(-$this->unscaled, $this->scale);
    }

    public function abs(): self
    {
        return new self(abs($this->unscaled), $this->scale);
    }

    /** Rounds half away from zero (the usual commercial rounding) to the given number of places. */
    public function roundTo(int $places): self
    {
        if ($places < 0 || $places > self::MAX_SCALE) {
            throw new ValidationException('Rounding precision must be between 0 and ' . self::MAX_SCALE . '.');
        }
        if ($places === $this->scale) {
            return $this;
        }
        if ($places > $this->scale) {
            return new self($this->scaleUp($this->unscaled, $places - $this->scale), $places);
        }

        $divisor = $this->pow10($this->scale - $places);
        $quotient = intdiv(abs($this->unscaled), $divisor);
        if (abs($this->unscaled) % $divisor * 2 >= $divisor) {
            ++$quotient;
        }

        return new self($this->unscaled < 0 ? -$quotient : $quotient, $places);
    }

    public function compare(self $other): int
    {
        [$a, $b] = $this->align($other);

        return $a <=> $b;
    }

    public function isZero(): bool
    {
        return $this->unscaled === 0;
    }

    public function isNegative(): bool
    {
        return $this->unscaled < 0;
    }

    public function isPositive(): bool
    {
        return $this->unscaled > 0;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Plain decimal notation (never exponent form).
     *
     * @param int|null $places fixed number of places (rounded half away from zero); null keeps the natural scale
     */
    public function toString(?int $places = null): string
    {
        $value = $places === null ? $this : $this->roundTo($places);
        $digits = (string) abs($value->unscaled);
        if ($value->scale > 0) {
            $digits = str_pad($digits, $value->scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$value->scale) . '.' . substr($digits, -$value->scale);
        }

        return ($value->unscaled < 0 ? '-' : '') . $digits;
    }

    /** Like {@see self::toString()} without insignificant trailing zeros, keeping at least $minPlaces. */
    public function toTrimmedString(int $minPlaces = 0): string
    {
        $places = $this->scale;
        $value = $this;
        while ($places > $minPlaces && $value->unscaled % 10 === 0) {
            $value = new self(intdiv($value->unscaled, 10), $places - 1);
            --$places;
        }

        return $value->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function shiftRight(int $places): self
    {
        $scale = $this->scale + $places;

        return $scale > self::MAX_SCALE ? $this->roundTo(max(0, $this->scale - ($scale - self::MAX_SCALE)))->shiftRight($places) : new self($this->unscaled, $scale);
    }

    /**
     * @return array{int, int, int} both unscaled values at the larger scale, and that scale
     */
    private function align(self $other): array
    {
        $scale = max($this->scale, $other->scale);

        return [
            $this->scaleUp($this->unscaled, $scale - $this->scale),
            $this->scaleUp($other->unscaled, $scale - $other->scale),
            $scale,
        ];
    }

    private function scaleUp(int $value, int $places): int
    {
        if ($places === 0) {
            return $value;
        }
        return self::checkedMultiply($value, $this->pow10($places));
    }

    private function pow10(int $exponent): int
    {
        $result = 1;
        for ($i = 0; $i < $exponent; ++$i) {
            $result *= 10;
        }

        return $result;
    }

    private static function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new ValidationException('Decimal overflow in addition.');
        }

        return $a + $b;
    }

    private static function checkedMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN || abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new ValidationException('Decimal overflow in multiplication.');
        }

        return $a * $b;
    }
}
