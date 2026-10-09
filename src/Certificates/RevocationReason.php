<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

enum RevocationReason: string
{
    case Unspecified = 'Unspecified';
    case Superseded = 'Superseded';
    case KeyCompromise = 'KeyCompromise';
}
