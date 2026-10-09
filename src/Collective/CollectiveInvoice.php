<?php

declare(strict_types=1);

namespace B4x\Ksef\Collective;

use B4x\Ksef\Invoice\Money;

/** An invoice to be included in a collective identifier, optionally with the payment made for it. */
final readonly class CollectiveInvoice
{
    public function __construct(
        public string $ksefNumber,
        public ?Money $payment = null,
        public ?string $description = null,
    ) {}
}
