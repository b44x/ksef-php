<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

/** How a person or entity is identified in the TEST data API. */
enum TestIdentifierType: string
{
    case Nip = 'Nip';
    case Pesel = 'Pesel';
    case Fingerprint = 'Fingerprint';
}
