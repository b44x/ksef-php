<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

use B4x\Ksef\Http\Payload;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/** Metadata of one invoice stored in KSeF. */
final readonly class InvoiceMetadata
{
    public function __construct(
        public string $ksefNumber,
        public string $invoiceNumber,
        public DateTimeImmutable $issueDate,
        public DateTimeImmutable $invoicingDate,
        public DateTimeImmutable $permanentStorageDate,
        public string $sellerNip,
        public ?string $sellerName,
        public ?string $buyerName,
        public string $buyerIdentifierType,
        public ?string $buyerIdentifier,
        public Decimal $netAmount,
        public Decimal $grossAmount,
        public Decimal $vatAmount,
        public string $currency,
        public string $invoiceType,
        public bool $hasAttachment,
        public string $hash,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $seller = $data->object('seller');
        $buyer = $data->object('buyer');
        $buyerId = $buyer->object('identifier');

        return new self(
            $data->string('ksefNumber'),
            $data->string('invoiceNumber'),
            $data->date('issueDate'),
            $data->date('invoicingDate'),
            $data->date('permanentStorageDate'),
            $seller->string('nip'),
            $seller->optionalString('name'),
            $buyer->optionalString('name'),
            $buyerId->string('type'),
            $buyerId->optionalString('value'),
            $data->decimal('netAmount'),
            $data->decimal('grossAmount'),
            $data->decimal('vatAmount'),
            $data->string('currency'),
            $data->string('invoiceType'),
            $data->bool('hasAttachment'),
            $data->string('invoiceHash'),
        );
    }
}
