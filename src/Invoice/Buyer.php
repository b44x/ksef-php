<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/** The recipient of the invoice (`Podmiot2`). */
final readonly class Buyer
{
    public function __construct(
        public BuyerIdentifier $identifier,
        public string $name,
        public ?Address $address = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $customerNumber = null,
    ) {}
}
