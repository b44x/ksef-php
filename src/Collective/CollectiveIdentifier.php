<?php

declare(strict_types=1);

namespace B4x\Ksef\Collective;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** A collective identifier as listed by KSeF (one payment that settles several invoices). */
final readonly class CollectiveIdentifier
{
    public function __construct(
        public string $number,
        public DateTimeImmutable $createdAt,
        public bool $createdInCurrentContext,
        /** Number of invoices; null in results that do not carry it (lookup by KSeF number). */
        public ?int $invoiceCount,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        return new self(
            $data->string('collectiveIdentifierNumber'),
            $data->date('dateCreated'),
            $data->bool('createdInCurrentContext'),
            $data->optionalInt('invoiceCount'),
        );
    }
}
