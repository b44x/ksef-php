<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** An extra charge or a deduction with its reason, shown in {@see AdditionalSettlement}. */
final readonly class Adjustment
{
    public function __construct(public Money $amount, public string $reason) {}

    public static function of(string|int $amount, string $reason, string $currency = 'PLN'): self
    {
        return new self(Money::of($amount, $currency), $reason);
    }
}
