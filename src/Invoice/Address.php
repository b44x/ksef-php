<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/**
 * A postal address in the free-form two-line layout of FA(3) (`TAdres`).
 *
 * Line 1 typically holds street and number, line 2 the postal code and city.
 */
final readonly class Address
{
    public function __construct(
        public string $countryCode,
        public string $line1,
        public ?string $line2 = null,
        public ?string $gln = null,
    ) {}

    public static function poland(string $line1, ?string $line2 = null): self
    {
        return new self('PL', $line1, $line2);
    }
}
