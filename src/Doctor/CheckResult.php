<?php

declare(strict_types=1);

namespace B4x\Ksef\Doctor;

/** The outcome of one check of the {@see Doctor}. */
final readonly class CheckResult
{
    /**
     * @param list<string> $details extra lines: findings, hints on how to fix a problem
     */
    public function __construct(
        public string $name,
        public CheckStatus $status,
        public string $message,
        public array $details = [],
    ) {}
}
