<?php

declare(strict_types=1);

namespace B4x\Ksef\Offline;

/** The situations in which invoices may be issued without KSeF, each with its own delivery deadline. */
enum OfflineMode
{
    /** Offline24: your own choice; deliver by the end of the next business day after the issue date. */
    case Offline24;

    /** KSeF was announced as unavailable: deliver by the end of the next business day after the unavailability ends. */
    case Unavailability;

    /** KSeF failure announced: deliver within 7 business days after the failure ends. */
    case Emergency;

    /** Business days after the reference date by which the invoice must be delivered. */
    public function businessDays(): int
    {
        return match ($this) {
            self::Offline24, self::Unavailability => 1,
            self::Emergency => 7,
        };
    }
}
