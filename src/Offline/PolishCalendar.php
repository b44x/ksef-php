<?php

declare(strict_types=1);

namespace B4x\Ksef\Offline;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Business days in Poland: Monday to Friday except public holidays. Used for the deadlines of offline invoicing.
 *
 * Holidays: 1 Jan, 6 Jan, Easter Sunday and Monday, 1 May, 3 May, Pentecost Sunday, Corpus Christi, 15 Aug, 1 Nov,
 * 11 Nov, 24 Dec (from 2025), 25 and 26 Dec. Days the Ministry declares non-business on top of that (for example
 * during an announced failure) can be given as `$extraNonBusinessDays`.
 */
final class PolishCalendar
{
    /** @var array<string, true> */
    private readonly array $extra;

    /**
     * @param list<DateTimeInterface|string> $extraNonBusinessDays dates (`Y-m-d`) that do not count as business days
     */
    public function __construct(array $extraNonBusinessDays = [])
    {
        $extra = [];
        foreach ($extraNonBusinessDays as $day) {
            $extra[$day instanceof DateTimeInterface ? $day->format('Y-m-d') : $day] = true;
        }
        $this->extra = $extra;
    }

    public function isBusinessDay(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');
        if (((int) $date->format('N')) >= 6 || isset($this->extra[$day])) {
            return false;
        }

        return !isset(self::holidays((int) $date->format('Y'))[$day]);
    }

    /**
     * The date that is `$count` business days after `$from` (the day of `$from` itself never counts).
     */
    public function addBusinessDays(DateTimeInterface $from, int $count): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        while ($count > 0) {
            $date = $date->modify('+1 day');
            if ($this->isBusinessDay($date)) {
                --$count;
            }
        }

        return $date;
    }

    /**
     * @return array<string, string> public holidays of the year: date => name
     */
    public static function holidays(int $year): array
    {
        $easter = self::easter($year);
        $holidays = [
            $year . '-01-01' => 'New Year',
            $year . '-01-06' => 'Epiphany',
            $easter->format('Y-m-d') => 'Easter Sunday',
            $easter->modify('+1 day')->format('Y-m-d') => 'Easter Monday',
            $year . '-05-01' => 'Labour Day',
            $year . '-05-03' => 'Constitution Day',
            $easter->modify('+49 days')->format('Y-m-d') => 'Pentecost Sunday',
            $easter->modify('+60 days')->format('Y-m-d') => 'Corpus Christi',
            $year . '-08-15' => 'Assumption',
            $year . '-11-01' => 'All Saints',
            $year . '-11-11' => 'Independence Day',
            $year . '-12-25' => 'Christmas',
            $year . '-12-26' => 'Second day of Christmas',
        ];
        if ($year >= 2025) {
            $holidays[$year . '-12-24'] = 'Christmas Eve';
        }

        return $holidays;
    }

    /** Easter Sunday of the Gregorian calendar (anonymous Gregorian algorithm). */
    private static function easter(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
