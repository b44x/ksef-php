<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Reference to an advance invoice settled by a final (`ROZ`) invoice. */
final readonly class AdvanceInvoiceReference
{
    private function __construct(
        public ?string $ksefNumber,
        public ?string $number,
    ) {}

    /** An advance invoice that was issued through KSeF. */
    public static function ksef(string $ksefNumber): self
    {
        return new self($ksefNumber, null);
    }

    /** An advance invoice issued outside KSeF, identified by its own number. */
    public static function external(string $number): self
    {
        return new self(null, $number);
    }
}
