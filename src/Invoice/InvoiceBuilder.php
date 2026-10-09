<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Fluent construction of an {@see Invoice}. Not thread-safe or reusable across invoices:
 * create a fresh builder (`Invoice::builder()`) for every document.
 */
final class InvoiceBuilder
{
    private ?string $number = null;
    private ?DateTimeImmutable $issueDate = null;
    private string $currency = 'PLN';
    private ?Seller $seller = null;
    private ?Buyer $buyer = null;

    /** @var list<ThirdParty> */
    private array $thirdParties = [];
    /** @var list<InvoiceLine> */
    private array $lines = [];
    private InvoiceType $type = InvoiceType::Standard;
    private ?DateTimeImmutable $saleDate = null;
    private ?string $issuePlace = null;
    private Annotations $annotations;
    private ?Payment $payment = null;
    private ?Correction $correction = null;
    private ?Decimal $exchangeRate = null;
    private ?string $footer = null;
    private ?AdvancePayment $advance = null;
    private ?Settlement $settlement = null;

    public function __construct()
    {
        $this->annotations = Annotations::none();
    }

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

    /** Date of delivery or service (`P_6`). */
    public function saleDate(DateTimeInterface|string $date): self
    {
        $this->saleDate = $this->date($date);

        return $this;
    }

    public function issuePlace(string $place): self
    {
        $this->issuePlace = $place;

        return $this;
    }

    public function currency(string $code): self
    {
        $this->currency = $code;

        return $this;
    }

    /** Exchange rate to PLN for foreign-currency invoices, as a decimal string. */
    public function exchangeRate(string $rate): self
    {
        $this->exchangeRate = Decimal::of($rate);

        return $this;
    }

    public function seller(Seller $seller): self
    {
        $this->seller = $seller;

        return $this;
    }

    public function buyer(Buyer $buyer): self
    {
        $this->buyer = $buyer;

        return $this;
    }

    public function addThirdParty(ThirdParty $party): self
    {
        $this->thirdParties[] = $party;

        return $this;
    }

    public function addLine(InvoiceLine $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function payment(Payment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    public function annotations(Annotations $annotations): self
    {
        $this->annotations = $annotations;

        return $this;
    }

    public function footer(string $text): self
    {
        $this->footer = $text;

        return $this;
    }

    /**
     * Turns the invoice into an advance invoice (`ZAL`), or with {@see self::correction()} into a correction of one
     * (`KOR_ZAL`; then `$advance->paid` is the change of the payment). Add the lines of the *order* with {@see self::addLine()}.
     */
    public function advance(AdvancePayment $advance): self
    {
        $this->advance = $advance;

        return $this;
    }

    /** Turns the invoice into a final invoice (`ROZ`) that settles the given advances, or with
     * {@see self::correction()} into a correction of one (`KOR_ROZ`). */
    public function settlement(Settlement $settlement): self
    {
        $this->settlement = $settlement;

        return $this;
    }

    /** Marks the invoice as simplified (`UPR`). */
    public function simplified(): self
    {
        $this->type = InvoiceType::Simplified;

        return $this;
    }

    /** Turns the invoice into a correction (`KOR`, or `KOR_ZAL` / `KOR_ROZ` combined with advance / settlement data). */
    public function correction(Correction $correction): self
    {
        $this->correction = $correction;

        return $this;
    }

    /**
     * @throws ValidationException when required data is missing or an invariant is violated
     */
    public function build(): Invoice
    {
        $missing = [];
        foreach (['number' => $this->number, 'issue date' => $this->issueDate, 'seller' => $this->seller, 'buyer' => $this->buyer] as $label => $value) {
            if ($value === null) {
                $missing[] = \sprintf('The invoice %s is required.', $label);
            }
        }
        if ($this->number === null || $this->issueDate === null || $this->seller === null || $this->buyer === null) {
            throw ValidationException::fromViolations($missing);
        }

        $type = match (true) {
            $this->type === InvoiceType::Simplified => InvoiceType::Simplified,
            $this->correction !== null && $this->advance !== null => InvoiceType::AdvanceCorrection,
            $this->correction !== null && $this->settlement !== null => InvoiceType::SettlementCorrection,
            $this->correction !== null => InvoiceType::Correction,
            $this->advance !== null => InvoiceType::Advance,
            $this->settlement !== null => InvoiceType::Settlement,
            default => InvoiceType::Standard,
        };

        return new Invoice(
            $this->number,
            $this->issueDate,
            $this->currency,
            $this->seller,
            $this->buyer,
            $this->lines,
            $type,
            $this->saleDate,
            $this->issuePlace,
            $this->annotations,
            $this->payment,
            $this->correction,
            $this->exchangeRate,
            $this->footer,
            $this->advance,
            $this->settlement,
            $this->thirdParties,
        );
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
