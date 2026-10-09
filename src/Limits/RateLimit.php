<?php

declare(strict_types=1);

namespace B4x\Ksef\Limits;

/** Allowed requests for one endpoint group. */
final readonly class RateLimit
{
    public function __construct(
        public int $perSecond,
        public int $perMinute,
        public int $perHour,
    ) {}
}
