<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The gross amount received in advance at one VAT rate; the tax contained in it is `gross * rate / (100 + rate)`. */
final readonly class AdvanceAmount
{
    public function __construct(public Money $gross, public VatRate $rate) {}
}
