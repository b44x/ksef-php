<?php

declare(strict_types=1);

namespace B4x\Ksef\Collective;

use B4x\Ksef\Http\Payload;

/** An invoice that belongs to a collective identifier. Payment details are hidden from entities without access. */
final readonly class CollectiveIdentifierInvoice
{
    public function __construct(
        public string $collectiveIdentifier,
        public string $ksefNumber,
        public bool $detailsHidden,
        public ?string $paymentAmount,
        public ?string $paymentCurrency,
        public ?string $description,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $payment = $data->optionalObject('payment');

        return new self(
            $data->string('collectiveIdentifierNumber'),
            $data->string('ksefNumber'),
            $data->bool('detailsHidden'),
            $payment === null ? null : $payment->decimal('amount')->toString(2),
            $payment?->string('currency'),
            $data->optionalString('description'),
        );
    }
}
