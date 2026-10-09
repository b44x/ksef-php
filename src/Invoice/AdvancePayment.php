<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/**
 * The payment an advance invoice (`ZAL`) documents: the amount received (gross), the VAT rate that applies
 * to it and the date it was received.
 *
 * KSeF takes the tax contained in an advance from the gross amount (art. 106f(1)(3) of the VAT Act):
 * `tax = gross * rate / (100 + rate)`. Only one rate per advance invoice is supported.
 */
final readonly class AdvancePayment
{
    public function __construct(
        public Money $paid,
        public VatRate $rate,
        public DateTimeImmutable $receivedOn,
    ) {}
}
