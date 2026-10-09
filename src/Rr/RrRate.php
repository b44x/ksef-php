<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Support\Decimal;

/** The flat-rate refund rate of an RR invoice (`P_9`, art. 116 of the VAT Act). */
enum RrRate: string
{
    /** 6.5 % (agricultural services). */
    case Rate6_5 = '6.5';
    /** 7 % (agricultural products). */
    case Rate7 = '7';

    public function percentage(): Decimal
    {
        return Decimal::of($this->value);
    }
}
