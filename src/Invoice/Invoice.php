<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/**
 * A structured VAT invoice (FA(3)).
 *
 * The object is immutable and always valid: the constructor checks every invariant that can be
 * verified without KSeF and throws a {@see ValidationException} listing all violations at once.
 * Use {@see self::builder()} for convenient construction.
 *
 * Supported: standard (`VAT`) and correction (`KOR`) invoices with net-priced lines, one seller
 * and one buyer, payment details and the common annotations. Other FA(3) features can be sent as
 * raw XML via {@see InvoiceDocument::fromXml()}.
 */
final readonly class Invoice
{
    /** @var list<InvoiceLine> */
    public array $lines;

    /** @var list<ThirdParty> */
    public array $thirdParties;

    /**
     * @param list<InvoiceLine> $lines
     * @param list<ThirdParty> $thirdParties additional parties (`Podmiot3`), up to 100
     * @param Decimal|null $exchangeRate rate to PLN for foreign-currency invoices (`KursWalutyZ`)
     *
     * @throws ValidationException
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issueDate,
        public string $currency,
        public Seller $seller,
        public Buyer $buyer,
        array $lines,
        public InvoiceType $type = InvoiceType::Standard,
        public ?DateTimeImmutable $saleDate = null,
        public ?string $issuePlace = null,
        public Annotations $annotations = new Annotations(),
        public ?Payment $payment = null,
        public ?Correction $correction = null,
        public ?Decimal $exchangeRate = null,
        public ?string $footer = null,
        public ?AdvancePayment $advance = null,
        public ?Settlement $settlement = null,
        array $thirdParties = [],
    ) {
        $this->lines = array_values($lines);
        $this->thirdParties = array_values($thirdParties);

        $violations = InvoiceValidator::violations($this);
        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }
    }

    public static function builder(): InvoiceBuilder
    {
        return new InvoiceBuilder();
    }

    /**
     * Net and tax sums. For an advance invoice these describe the advance payment, otherwise the lines.
     */
    public function totals(): InvoiceTotals
    {
        if ($this->type->isAdvance() && $this->advance !== null) {
            return InvoiceTotals::forAdvance($this->advance, $this->currency, $this->exchangeRate);
        }

        return InvoiceTotals::calculate($this->lines, $this->currency, $this->exchangeRate);
    }

    /**
     * Value of the order an advance invoice refers to, tax included (`WartoscZamowienia`).
     */
    public function orderValue(): Decimal
    {
        // After a correction the order is worth what the "current" lines say; "before" lines only document the old state.
        $lines = $this->type === InvoiceType::AdvanceCorrection
            ? array_values(array_filter($this->lines, static fn(InvoiceLine $line): bool => $line->state === LineState::Current))
            : $this->lines;

        return InvoiceTotals::calculate($lines, $this->currency, $this->exchangeRate)->gross();
    }

    /**
     * The amount that `P_15` carries: the gross total; for an advance invoice the payment received;
     * for a settlement invoice what remains to be paid after the advances.
     */
    public function amountDue(): Decimal
    {
        return match ($this->type) {
            InvoiceType::Advance, InvoiceType::AdvanceCorrection => $this->advance?->paid->amount->roundTo(2) ?? $this->totals()->gross(),
            InvoiceType::Settlement, InvoiceType::SettlementCorrection => $this->totals()->gross()->subtract($this->settlement?->advancesPaid->amount->roundTo(2) ?? Decimal::of('0.00')),
            default => $this->totals()->gross(),
        };
    }

    public function hasLinesWith(VatRate $rate): bool
    {
        foreach ($this->lines as $line) {
            if ($line->vatRate === $rate) {
                return true;
            }
        }

        return false;
    }
}
