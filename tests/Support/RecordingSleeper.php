<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use B4x\Ksef\Http\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
