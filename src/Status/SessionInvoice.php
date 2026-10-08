<?php

declare(strict_types=1);

namespace Ksef\Status;

use DateTimeImmutable;
use Ksef\Http\Payload;

/** An invoice as reported by the session endpoints. */
final readonly class SessionInvoice
{
    public function __construct(
        public int $ordinalNumber,
        public string $referenceNumber,
        public string $invoiceHash,
        public InvoiceStatus $status,
        public ?string $ksefNumber,
        public ?string $invoiceNumber,
        public DateTimeImmutable $invoicingDate,
        public ?DateTimeImmutable $acquisitionDate,
        public ?DateTimeImmutable $permanentStorageDate,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        return new self(
            $data->int('ordinalNumber'),
            $data->string('referenceNumber'),
            $data->string('invoiceHash'),
            InvoiceStatus::fromPayload($data->object('status')),
            $data->optionalString('ksefNumber'),
            $data->optionalString('invoiceNumber'),
            $data->date('invoicingDate'),
            $data->optionalDate('acquisitionDate'),
            $data->optionalDate('permanentStorageDate'),
        );
    }

    /**
     * @throws \Ksef\Exception\InvoiceRejectedException when the invoice was not accepted
     * @throws \Ksef\Exception\InvoiceException when processing has not finished yet
     */
    public function assertAccepted(): self
    {
        if ($this->status->isRejected()) {
            throw \Ksef\Exception\InvoiceRejectedException::fromStatus($this->status, $this->referenceNumber);
        }
        if (!$this->status->isAccepted()) {
            throw new \Ksef\Exception\InvoiceException(\sprintf('Invoice %s is still being processed (status %d).', $this->referenceNumber, $this->status->code), $this->status);
        }

        return $this;
    }
}
