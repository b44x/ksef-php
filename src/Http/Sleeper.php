<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

/** Abstraction over sleeping so that retry and polling logic is testable without real delays. */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
