<?php

declare(strict_types=1);

namespace B4x\Ksef\Polling;

use B4x\Ksef\Exception\ConfigurationException;

/**
 * How long and how often an asynchronous KSeF operation is polled.
 *
 * Polling always ends: either the operation reaches a terminal state, or the timeout or the
 * attempt limit is reached and a {@see \B4x\Ksef\Exception\PollingTimeoutException} is thrown.
 */
final readonly class PollingPolicy
{
    /**
     * @param float $initialDelaySeconds wait before the second poll
     * @param float $maxDelaySeconds ceiling for the growing delay
     * @param float $backoffFactor multiplier applied to the delay after each poll (1.0 = constant interval)
     * @param float $timeoutSeconds total time budget
     * @param int|null $maxAttempts optional hard limit of polls
     */
    public function __construct(
        public float $initialDelaySeconds = 1.0,
        public float $maxDelaySeconds = 10.0,
        public float $backoffFactor = 1.5,
        public float $timeoutSeconds = 120.0,
        public ?int $maxAttempts = null,
    ) {
        if ($initialDelaySeconds < 0 || $maxDelaySeconds < $initialDelaySeconds || $backoffFactor < 1.0 || $timeoutSeconds <= 0) {
            throw new ConfigurationException('Invalid polling policy: delays must be non-negative, the factor at least 1.0 and the timeout positive.');
        }
        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new ConfigurationException('PollingPolicy::$maxAttempts must be at least 1 when set.');
        }
    }

    /** A policy that checks exactly once and never waits. */
    public static function once(): self
    {
        return new self(0.0, 0.0, 1.0, 1.0, 1);
    }

    public function delayAfter(int $attempt): float
    {
        return min($this->maxDelaySeconds, $this->initialDelaySeconds * ($this->backoffFactor ** max(0, $attempt - 1)));
    }
}
