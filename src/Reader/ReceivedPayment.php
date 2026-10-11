<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Invoice\PaymentMethod;
use DateTimeImmutable;

/** How and when the invoice is paid (`Platnosc`). */
final readonly class ReceivedPayment
{
    /**
     * @param list<DateTimeImmutable> $dueDates
     * @param list<string> $bankAccounts
     * @param list<ReceivedPartialPayment> $partialPayments
     * @param bool|null $partialPaymentsComplete true when the part payments cover the whole invoice (`ZnacznikZaplatyCzesciowej` 2)
     */
    public function __construct(
        public ?DateTimeImmutable $paidOn = null,
        public array $dueDates = [],
        public ?int $methodCode = null,
        public ?string $otherMethod = null,
        public array $bankAccounts = [],
        public array $partialPayments = [],
        public ?bool $partialPaymentsComplete = null,
        public ?string $skontoConditions = null,
        public ?string $skontoAmount = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->paidOn !== null || $this->partialPaymentsComplete === true;
    }

    public function method(): ?PaymentMethod
    {
        return $this->methodCode === null ? null : PaymentMethod::tryFrom($this->methodCode);
    }
}
