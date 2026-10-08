<?php

declare(strict_types=1);

namespace Ksef\Invoice;

use DateTimeImmutable;
use Ksef\Exception\ValidationException;
use Ksef\Support\Decimal;

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

    /**
     * @param list<InvoiceLine> $lines
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
    ) {
        $this->lines = array_values($lines);

        $violations = InvoiceValidator::violations($this);
        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }
    }

    public static function builder(): InvoiceBuilder
    {
        return new InvoiceBuilder();
    }

    public function totals(): InvoiceTotals
    {
        return InvoiceTotals::calculate($this->lines, $this->currency, $this->exchangeRate);
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
