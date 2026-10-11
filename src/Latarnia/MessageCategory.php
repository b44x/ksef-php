<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

enum MessageCategory: string
{
    case Failure = 'FAILURE';
    case TotalFailure = 'TOTAL_FAILURE';
    case Maintenance = 'MAINTENANCE';
}
