<?php

declare(strict_types=1);

namespace Ksef\Tests\Support;

use Ksef\Http\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
