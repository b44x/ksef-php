<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;

/**
 * A payment received before the sale that this invoice documents (`ZaliczkaCzesciowa`, art. 106b(1)(4) of the VAT Act):
 * for example a part payment that was not invoiced as an advance, shown on the final invoice. The invoice total
 * (`P_15`) stays what it is; the difference between it and the sum of these payments is what remains to be paid.
 *
 * On a correction the amount is the correction of the amount shown on the corrected invoice (it may be negative).
 */
final readonly class PriorPayment
{
    /**
     * @param Decimal|null $exchangeRate rate used to calculate the tax of a foreign-currency payment (`KursWalutyZW`)
     */
    public function __construct(
        public DateTimeImmutable $receivedOn,
        public Money $amount,
        public ?Decimal $exchangeRate = null,
    ) {}
}
