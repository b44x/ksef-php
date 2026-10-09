<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Decimal;

/** An amount in a specific currency (ISO 4217 code). */
final readonly class Money
{
    private function __construct(
        public Decimal $amount,
        public string $currency,
    ) {}

    /**
     * @param string|int $amount decimal string such as "123.45"; floats are intentionally not accepted
     */
    public static function of(string|int $amount, string $currency = 'PLN'): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ValidationException(\sprintf('"%s" is not an ISO 4217 currency code.', $currency), [\sprintf('Invalid currency: %s', $currency)]);
        }

        return new self(Decimal::of($amount), $currency);
    }

    public static function pln(string|int $amount): self
    {
        return self::of($amount, 'PLN');
    }

    public function multiply(Decimal $factor): self
    {
        return new self($this->amount->multiply($factor), $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->equals($other->amount);
    }

    public function __toString(): string
    {
        return $this->amount->toString() . ' ' . $this->currency;
    }
}
