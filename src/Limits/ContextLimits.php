<?php

declare(strict_types=1);

namespace B4x\Ksef\Limits;

/** Limits that apply to the current authentication context. */
final readonly class ContextLimits
{
    public function __construct(
        public SessionLimits $onlineSession,
        public SessionLimits $batchSession,
    ) {}
}
