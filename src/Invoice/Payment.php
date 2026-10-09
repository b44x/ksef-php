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
     * @param list<PartialPayment> $partialPayments payments already made towards the invoice (instead of {@see self::$paidOn})
     * @param string|null $otherMethod description of a payment method that {@see PaymentMethod} does not cover
     * @param string|null $skontoConditions conditions for the early-payment discount (`Skonto`)
     * @param string|null $skontoAmount the discount, as text (for example "2% within 7 days")
     */
    public function __construct(
        public ?PaymentMethod $method = null,
        public ?DateTimeImmutable $paidOn = null,
        public array $dueDates = [],
        public array $bankAccounts = [],
        public array $partialPayments = [],
        public ?string $otherMethod = null,
        public ?string $skontoConditions = null,
        public ?string $skontoAmount = null,
    ) {}

    /** True when the partial payments add up to at least the given invoice total, so the last one settles it. */
    public function partialPaymentsComplete(\B4x\Ksef\Support\Decimal $invoiceTotal): bool
    {
        $sum = \B4x\Ksef\Support\Decimal::of('0.00');
        foreach ($this->partialPayments as $part) {
            $sum = $sum->add($part->amount->amount->roundTo(2));
        }

        return $sum->compare($invoiceTotal) >= 0;
    }

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

    /**
     * @param list<PartialPayment> $partialPayments
     * @param list<string> $bankAccounts
     */
    public static function partlyPaid(array $partialPayments, ?DateTimeImmutable $dueDate = null, array $bankAccounts = []): self
    {
        return new self(PaymentMethod::BankTransfer, null, $dueDate === null ? [] : [$dueDate], $bankAccounts, $partialPayments);
    }
}
