<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $now): void
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('%+d seconds', $seconds));
    }
}
