<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/** Payment information (`Platnosc`). */
final readonly class Payment
{
    /**
     * @param list<DateTimeImmutable> $dueDates
     * @param list<string> $bankAccounts account numbers (10 to 34 characters), IBAN without spaces
     */
    public function __construct(
        public ?PaymentMethod $method = null,
        public ?DateTimeImmutable $paidOn = null,
        public array $dueDates = [],
        public array $bankAccounts = [],
    ) {}

    public static function paid(DateTimeImmutable $paidOn, ?PaymentMethod $method = null): self
    {
        return new self($method, $paidOn);
    }

    /**
     * @param list<string> $bankAccounts
     */
    public static function dueOn(DateTimeImmutable $dueDate, PaymentMethod $method = PaymentMethod::BankTransfer, array $bankAccounts = []): self
    {
        return new self($method, null, [$dueDate], $bankAccounts);
    }
}
