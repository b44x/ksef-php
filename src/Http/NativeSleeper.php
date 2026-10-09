<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

/** Blocks the current process using usleep(). */
final class NativeSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
