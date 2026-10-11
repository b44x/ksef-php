<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Offline;

use B4x\Ksef\Offline\OfflineDeadline;
use B4x\Ksef\Offline\OfflineMode;
use B4x\Ksef\Offline\PolishCalendar;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineDeadlineTest extends TestCase
{
    /**
     * @return array<int|string, array{int, string}>
     */
    public static function easterAndMovableHolidays(): array
    {
        return [
            '2024' => [2024, '2024-03-31'],
            '2025' => [2025, '2025-04-20'],
            '2026' => [2026, '2026-04-05'],
            '2027' => [2027, '2027-03-28'],
        ];
    }

    #[DataProvider('easterAndMovableHolidays')]
    public function testEasterAndTheHolidaysThatFollowIt(int $year, string $easter): void
    {
        $holidays = PolishCalendar::holidays($year);
        $date = new DateTimeImmutable($easter);

        self::assertArrayHasKey($easter, $holidays);
        self::assertArrayHasKey($date->modify('+1 day')->format('Y-m-d'), $holidays, 'Easter Monday');
        self::assertArrayHasKey($date->modify('+49 days')->format('Y-m-d'), $holidays, 'Pentecost');
        self::assertArrayHasKey($date->modify('+60 days')->format('Y-m-d'), $holidays, 'Corpus Christi');
    }

    public function testChristmasEveIsAHolidayFrom2025(): void
    {
        self::assertArrayNotHasKey('2024-12-24', PolishCalendar::holidays(2024));
        self::assertArrayHasKey('2025-12-24', PolishCalendar::holidays(2025));
    }

    public function testOffline24MeansTheEndOfTheNextBusinessDay(): void
    {
        $deadline = new OfflineDeadline();

        self::assertSame('2026-06-02 23:59:59', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-01'))->format('Y-m-d H:i:s'), 'Monday -> Tuesday');
        self::assertSame('2026-06-08 23:59:59', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-05'))->format('Y-m-d H:i:s'), 'Friday -> Monday');
        self::assertSame('2026-06-05 23:59:59', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-03'))->format('Y-m-d H:i:s'), 'Corpus Christi (Thursday 4 June) is skipped');
        self::assertSame('2026-06-08 23:59:59', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-06'))->format('Y-m-d H:i:s'), 'issued on a Saturday');
        self::assertSame('Europe/Warsaw', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-01'))->getTimezone()->getName());
    }

    public function testEmergencyModeAllowsSevenBusinessDaysAcrossTheHolidays(): void
    {
        $deadline = new OfflineDeadline();

        self::assertSame('2027-01-04 23:59:59', $deadline->for(OfflineMode::Emergency, new DateTimeImmutable('2026-12-21'))->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-11 23:59:59', $deadline->for(OfflineMode::Emergency, new DateTimeImmutable('2026-06-01'))->format('Y-m-d H:i:s'));
    }

    public function testExtraNonBusinessDaysMoveTheDeadline(): void
    {
        $deadline = new OfflineDeadline(new PolishCalendar(['2026-06-02']));

        self::assertSame('2026-06-03 23:59:59', $deadline->for(OfflineMode::Offline24, new DateTimeImmutable('2026-06-01'))->format('Y-m-d H:i:s'));
    }

    public function testOverdueIsAfterTheEndOfTheDeadlineDay(): void
    {
        $deadline = new OfflineDeadline();
        $issued = new DateTimeImmutable('2026-06-01');

        self::assertFalse($deadline->isOverdue(OfflineMode::Offline24, $issued, new DateTimeImmutable('2026-06-02 23:59:00', new DateTimeZone('Europe/Warsaw'))));
        self::assertTrue($deadline->isOverdue(OfflineMode::Offline24, $issued, new DateTimeImmutable('2026-06-03 00:00:01', new DateTimeZone('Europe/Warsaw'))));
    }
}
