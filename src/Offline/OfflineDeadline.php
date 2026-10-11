<?php

declare(strict_types=1);

namespace B4x\Ksef\Offline;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * When an invoice issued offline has to reach KSeF (see docs/OFFLINE.md for the modes).
 *
 * The deadline is the end of the last day, in Polish time. It follows the rules as published; always check the
 * Ministry's announcements, because a failure or maintenance can change the dates. Give extra non-business days to
 * {@see PolishCalendar} when it does.
 */
final class OfflineDeadline
{
    public function __construct(private readonly PolishCalendar $calendar = new PolishCalendar()) {}

    /**
     * @param DateTimeInterface $reference the issue date for {@see OfflineMode::Offline24}; the day the unavailability
     *                                     or failure ended for the other modes
     */
    public function for(OfflineMode $mode, DateTimeInterface $reference): DateTimeImmutable
    {
        $zone = new DateTimeZone('Europe/Warsaw');
        $day = (new DateTimeImmutable($reference->format('Y-m-d'), $zone));

        return $this->calendar->addBusinessDays($day, $mode->businessDays())->setTime(23, 59, 59);
    }

    public function isOverdue(OfflineMode $mode, DateTimeInterface $reference, DateTimeInterface $now): bool
    {
        return DateTimeImmutable::createFromInterface($now) > $this->for($mode, $reference);
    }
}
