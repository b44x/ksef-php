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

    /** @var list<AdditionalInfo> */
    public array $additionalInfo;

    /** @var list<string> */
    public array $warehouseDocuments;

    /**
     * @param list<InvoiceLine> $lines
     * @param list<ThirdParty> $thirdParties additional parties (`Podmiot3`), up to 100
     * @param list<AdditionalInfo> $additionalInfo free key/value remarks (`DodatkowyOpis`)
     * @param list<string> $warehouseDocuments numbers of the warehouse issue documents (`WZ`)
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
        public ?AuthorizedEntity $authorizedEntity = null,
        array $additionalInfo = [],
        array $warehouseDocuments = [],
        public ?AdditionalSettlement $additionalSettlement = null,
        public ?TransactionTerms $terms = null,
        public ?Attachment $attachment = null,
    ) {
        $this->lines = array_values($lines);
        $this->thirdParties = array_values($thirdParties);
        $this->additionalInfo = array_values($additionalInfo);
        $this->warehouseDocuments = array_values($warehouseDocuments);

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
     * The sums the header reports (`P_13_x`, `P_14_x`): for an advance invoice the advance payment, for a settlement
     * invoice the part of the sale that remains after the advances, otherwise the lines.
     */
    public function totals(): InvoiceTotals
    {
        if ($this->type->isAdvance() && $this->advance !== null) {
            return InvoiceTotals::forAdvance($this->advance, $this->currency, $this->exchangeRate);
        }
        if ($this->type->isSettlement()) {
            return $this->saleTotals()->withoutAdvances($this->advanceParts(), $this->currency, $this->exchangeRate);
        }

        return $this->saleTotals();
    }

    /** Net and tax of everything the lines sell, before any advances are deducted. */
    public function saleTotals(): InvoiceTotals
    {
        return InvoiceTotals::calculate($this->lines, $this->currency, $this->exchangeRate);
    }

    /**
     * The advances of a settlement invoice split by VAT rate; empty when there are none or when the rate cannot be
     * determined (the validator reports that case).
     *
     * @return list<AdvanceAmount>
     */
    public function advanceParts(): array
    {
        $settlement = $this->settlement;
        if ($settlement === null) {
            return [];
        }
        if ($settlement->advanceAmounts !== []) {
            return $settlement->advanceAmounts;
        }
        if ($settlement->advancesPaid->amount->isZero()) {
            return [];
        }
        if ($settlement->advanceRate !== null) {
            return [new AdvanceAmount($settlement->advancesPaid, $settlement->advanceRate)];
        }

        $rates = [];
        foreach ($this->lines as $line) {
            $rates[$line->vatRate->bucket()] = $line->vatRate;
        }

        return \count($rates) === 1 ? [new AdvanceAmount($settlement->advancesPaid, array_values($rates)[0])] : [];
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
