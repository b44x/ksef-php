<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/** A payment of part of the amount made before the invoice was issued (`ZaplataCzesciowa`). */
final readonly class PartialPayment
{
    public function __construct(
        public Money $amount,
        public DateTimeImmutable $paidOn,
        public ?PaymentMethod $method = null,
    ) {}
}
