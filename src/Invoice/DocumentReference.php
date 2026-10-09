<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/** A reference to a contract or an order: its date, its number, or both. */
final readonly class DocumentReference
{
    public function __construct(
        public ?string $number = null,
        public ?DateTimeImmutable $date = null,
    ) {}
}
