<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/** A part payment already made towards the invoice (`ZaplataCzesciowa`). */
final readonly class ReceivedPartialPayment
{
    public function __construct(
        public Decimal $amount,
        public DateTimeImmutable $paidOn,
        public ?int $methodCode = null,
    ) {}
}
