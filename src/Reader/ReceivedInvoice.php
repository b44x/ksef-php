<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceType;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/**
 * An FA(3) invoice read from XML: what a buyer, an accountant or a visualisation needs, with the amounts exactly as
 * the issuer wrote them.
 *
 * This is a *read model*. It is separate from {@see \B4x\Ksef\Invoice\Invoice} on purpose: that one is a validated
 * aggregate for issuing, while a received invoice may carry anything the schema allows, and its figures are the
 * issuer's, never recalculated. The original XML stays available in {@see self::$document}.
 */
final readonly class ReceivedInvoice
{
    /**
     * @param list<ReceivedParty> $thirdParties
     * @param list<ReceivedLine> $lines
     * @param list<TaxSummaryRow> $taxSummary
     * @param list<string> $advanceInvoices KSeF numbers (or numbers, for invoices issued outside KSeF) of the advance invoices a final invoice settles
     * @param list<ReceivedNote> $notes
     * @param list<string> $warehouseDocuments
     * @param Decimal $total the amount due (`P_15`)
     * @param DateTimeImmutable|null $periodFrom the start of the period an invoice for a period covers (`OkresFa`)
     */
    public function __construct(
        public InvoiceDocument $document,
        public string $number,
        public DateTimeImmutable $issueDate,
        public string $currency,
        public InvoiceType $type,
        public ReceivedParty $seller,
        public ReceivedParty $buyer,
        public Decimal $total,
        public array $thirdParties = [],
        public ?DateTimeImmutable $saleDate = null,
        public ?DateTimeImmutable $periodFrom = null,
        public ?DateTimeImmutable $periodTo = null,
        public ?string $issuePlace = null,
        public array $lines = [],
        public array $taxSummary = [],
        public ?Decimal $exchangeRate = null,
        public ?ReceivedPayment $payment = null,
        public ?ReceivedCorrection $correction = null,
        public array $advanceInvoices = [],
        public array $notes = [],
        public array $warehouseDocuments = [],
        public ?string $footer = null,
        public bool $hasAttachment = false,
        public ReceivedAnnotations $annotations = new ReceivedAnnotations(),
        /** The value of the order an advance invoice refers to (`WartoscZamowienia`). */
        public ?Decimal $orderValue = null,
        public ?ReceivedParty $authorizedEntity = null,
    ) {}

    public function isCorrection(): bool
    {
        return $this->type->isCorrection();
    }

    /** The summed net values of the tax summary. */
    public function totalNet(): Decimal
    {
        $sum = Decimal::zero();
        foreach ($this->taxSummary as $row) {
            $sum = $sum->add($row->net);
        }

        return $sum;
    }

    /** The summed tax of the tax summary, in the invoice currency. */
    public function totalTax(): Decimal
    {
        $sum = Decimal::zero();
        foreach ($this->taxSummary as $row) {
            $sum = $sum->add($row->tax ?? Decimal::zero());
        }

        return $sum;
    }
}
