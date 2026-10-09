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
     * Whether polling can stop. With `$untilStored`, an accepted invoice only settles once it is
     * permanently stored; rejections and in-progress states behave the same either way.
     */
    public function isSettled(bool $untilStored = false): bool
    {
        if (!$this->status->isTerminal()) {
            return false;
        }

        return !$untilStored || !$this->status->isAccepted() || $this->isPermanentlyStored();
    }

    /** The KSeF number under which the document is stored: its own, or (duplicate, 440) the original's. */
    public function resolvedKsefNumber(): ?string
    {
        return $this->ksefNumber ?? $this->status->originalKsefNumber();
    }

    /**
     * Like {@see self::assertAccepted()}, but also succeeds for a duplicate (440): the document is then
     * already stored in KSeF, which is the desired end state after a recovered submission.
     *
     * @throws \Ksef\Exception\InvoiceRejectedException when the document is not stored in KSeF
     */
    public function assertStored(): self
    {
        if ($this->status->isDuplicate() && $this->status->originalKsefNumber() !== null) {
            return $this;
        }

        return $this->assertAccepted();
    }

    /**
     * True once KSeF has permanently stored the invoice. Only then can it be downloaded; an
     * accepted invoice (status 200) is briefly "not stored yet".
     */
    public function isPermanentlyStored(): bool
    {
        return $this->permanentStorageDate !== null;
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
