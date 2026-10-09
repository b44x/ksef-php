<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Exception\ConfigurationException;

/** Exponential backoff with optional jitter; honours the Retry-After header up to a cap. */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts total number of attempts including the first (1 disables retries)
     * @param float $baseDelaySeconds delay before the first retry
     * @param float $maxDelaySeconds upper bound for a single delay; larger Retry-After values are not waited for
     * @param float $jitterRatio 0.0 (none) to 1.0: random fraction of the delay that is added
     */
    public function __construct(
        public int $maxAttempts = 3,
        public float $baseDelaySeconds = 0.5,
        public float $maxDelaySeconds = 30.0,
        public float $jitterRatio = 0.2,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException('RetryPolicy::$maxAttempts must be at least 1.');
        }
        if ($baseDelaySeconds < 0 || $maxDelaySeconds < 0 || $jitterRatio < 0 || $jitterRatio > 1) {
            throw new ConfigurationException('RetryPolicy delays must be non-negative and the jitter ratio within 0..1.');
        }
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Delay before the next attempt, or null when the wait would exceed the allowed maximum.
     *
     * @param int $attempt the attempt that just failed (1-based)
     * @param int|null $retryAfter value of the Retry-After header in seconds, if any
     */
    public function delayBefore(int $attempt, ?int $retryAfter = null): ?float
    {
        if ($retryAfter !== null) {
            return $retryAfter > $this->maxDelaySeconds ? null : (float) $retryAfter;
        }

        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** (max(1, $attempt) - 1)));
        if ($this->jitterRatio > 0.0) {
            $delay += $delay * $this->jitterRatio * (random_int(0, 1000) / 1000);
        }

        return min($delay, $this->maxDelaySeconds);
    }
}
