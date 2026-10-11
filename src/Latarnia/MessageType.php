<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

enum MessageType: string
{
    case FailureStart = 'FAILURE_START';
    case FailureEnd = 'FAILURE_END';
    case MaintenanceAnnouncement = 'MAINTENANCE_ANNOUNCEMENT';
}
