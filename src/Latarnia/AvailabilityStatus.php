<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

/** How KSeF is doing right now, as announced by the Ministry's availability service ("Latarnia"). */
enum AvailabilityStatus: string
{
    case Available = 'AVAILABLE';
    case Maintenance = 'MAINTENANCE';
    case Failure = 'FAILURE';
    case TotalFailure = 'TOTAL_FAILURE';
}
