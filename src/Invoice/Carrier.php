<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The carrier of a transport (`Przewoznik`). */
final readonly class Carrier
{
    public function __construct(
        public BuyerIdentifier $identifier,
        public string $name,
        public Address $address,
    ) {}
}
