<?php

declare(strict_types=1);

namespace B4x\Ksef\Peppol;

use DateTimeImmutable;

/** A Peppol service provider registered in KSeF. */
final readonly class PeppolProvider
{
    public function __construct(public string $id, public string $name, public DateTimeImmutable $registeredAt) {}
}
