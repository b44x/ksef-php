<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeInterface;

/** Fluent construction of an {@see RrInvoice}. */
final class RrInvoiceBuilder
{
    private ?string $number = null;
    private ?DateTimeImmutable $issueDate = null;
    private ?RrParty $supplier = null;
    private ?RrParty $buyer = null;
    /** @var list<RrLine> */
    private array $lines = [];
    private string $currency = 'PLN';
    private ?DateTimeImmutable $purchaseDate = null;
    private ?string $issuePlace = null;
    private ?RrPayment $payment = null;
    private ?RrCorrection $correction = null;
    private ?string $amountInWords = null;
    private ?string $footer = null;

    /** The invoice number (`P_4C`). */
    public function number(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function issueDate(DateTimeInterface|string $date): self
    {
        $this->issueDate = $this->date($date);

        return $this;
    }

    /** Date of the purchase when it is the same for all lines (`P_4A`); otherwise set it per line. */
    public function purchaseDate(DateTimeInterface|string $date): self
    {
        $this->purchaseDate = $this->date($date);

        return $this;
    }

    public function issuePlace(string $place): self
    {
        $this->issuePlace = $place;

        return $this;
    }

    /** The farmer who sold the products. */
    public function supplier(RrParty $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    /** The buyer who issues the invoice. */
    public function buyer(RrParty $buyer): self
    {
        $this->buyer = $buyer;

        return $this;
    }

    public function addLine(RrLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function payment(RrPayment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    /** Turns the invoice into a correction (`KOR_VAT_RR`). */
    public function correction(RrCorrection $correction): self
    {
        $this->correction = $correction;

        return $this;
    }

    /** Overrides the generated Polish wording of the total (needed for currencies other than PLN). */
    public function amountInWords(string $words): self
    {
        $this->amountInWords = $words;

        return $this;
    }

    public function footer(string $text): self
    {
        $this->footer = $text;

        return $this;
    }

    /**
     * @throws ValidationException when required data is missing or an invariant is violated
     */
    public function build(): RrInvoice
    {
        $missing = [];
        foreach (['number' => $this->number, 'issue date' => $this->issueDate, 'supplier' => $this->supplier, 'buyer' => $this->buyer] as $label => $value) {
            if ($value === null) {
                $missing[] = \sprintf('The RR invoice %s is required.', $label);
            }
        }
        if ($this->number === null || $this->issueDate === null || $this->supplier === null || $this->buyer === null) {
            throw ValidationException::fromViolations($missing);
        }

        return new RrInvoice($this->number, $this->issueDate, $this->supplier, $this->buyer, $this->lines, $this->currency, $this->purchaseDate, $this->issuePlace, $this->payment, $this->correction, $this->amountInWords, $this->footer);
    }

    private function date(DateTimeInterface|string $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException(\sprintf('"%s" is not a valid date (expected YYYY-MM-DD).', $date), [\sprintf('Invalid date: %s', $date)]);
        }

        return $parsed;
    }
}
