<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use B4x\Ksef\Http\Sleeper;

/** Sleeper that advances a {@see MutableClock} instead of blocking, so time-based logic is deterministic. */
final class ClockSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly MutableClock $clock) {}

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock->advance((int) ceil($seconds));
    }
}
