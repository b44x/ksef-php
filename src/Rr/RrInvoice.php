<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\LineState;
use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\PolishAmountInWords;
use DateTimeImmutable;

/**
 * A flat-rate farmer purchase invoice (FA_RR, art. 116 of the VAT Act): the buyer issues it for the products
 * or services bought from a farmer and pays the flat-rate refund of tax on top of the price.
 *
 * Issuing it in KSeF needs the farmer's `RrInvoicing` authorisation for the buyer
 * (`KsefClient::grantAuthorization()` by the farmer). A correction (`KOR_VAT_RR`) lists the corrected lines as
 * "before" and "after" lines, and the totals become differences.
 */
final readonly class RrInvoice
{
    /** @var list<RrLine> */
    public array $lines;

    /**
     * @param list<RrLine> $lines
     * @param string|null $amountInWords the total in words (`P_12_2`); generated in Polish for PLN when null
     *
     * @throws ValidationException
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issueDate,
        public RrParty $supplier,
        public RrParty $buyer,
        array $lines,
        public string $currency = 'PLN',
        public ?DateTimeImmutable $purchaseDate = null,
        public ?string $issuePlace = null,
        public ?RrPayment $payment = null,
        public ?RrCorrection $correction = null,
        public ?string $amountInWords = null,
        public ?string $footer = null,
    ) {
        $this->lines = array_values($lines);

        $violations = RrValidator::violations($this);
        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }
    }

    public static function builder(): RrInvoiceBuilder
    {
        return new RrInvoiceBuilder();
    }

    public function isCorrection(): bool
    {
        return $this->correction !== null;
    }

    /** Value of the purchases without the refund (`P_11_1`); a difference on corrections. */
    public function value(): Decimal
    {
        return $this->sum(static fn(RrLine $line): Decimal => $line->value());
    }

    /** Flat-rate refund of tax (`P_11_2`); a difference on corrections. */
    public function refund(): Decimal
    {
        return $this->sum(static fn(RrLine $line): Decimal => $line->refund());
    }

    /** Total due including the refund (`P_12_1`); a difference on corrections. */
    public function total(): Decimal
    {
        return $this->value()->add($this->refund());
    }

    /** The total in words (`P_12_2`). */
    public function totalInWords(): string
    {
        return $this->amountInWords ?? PolishAmountInWords::pln($this->total());
    }

    /**
     * @param callable(RrLine): Decimal $amount
     */
    private function sum(callable $amount): Decimal
    {
        $sum = Decimal::of('0.00');
        foreach ($this->lines as $line) {
            $sum = $sum->add($line->sign($amount($line)));
        }

        return $sum;
    }

    public function hasBeforeLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->state === LineState::Before) {
                return true;
            }
        }

        return false;
    }
}
