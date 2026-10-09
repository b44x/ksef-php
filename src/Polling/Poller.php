<?php

declare(strict_types=1);

namespace B4x\Ksef\Polling;

use B4x\Ksef\Exception\PollingTimeoutException;
use B4x\Ksef\Http\NativeSleeper;
use B4x\Ksef\Http\Sleeper;
use B4x\Ksef\Support\SystemClock;
use Psr\Clock\ClockInterface;

/** Repeats a status check until it reports a terminal state or the policy's budget is used up. */
final class Poller
{
    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly Sleeper $sleeper = new NativeSleeper(),
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $check fetches the current state
     * @param callable(T): bool $isTerminal true when no further change is expected
     *
     * @return T the first terminal state
     *
     * @throws PollingTimeoutException when the budget is exhausted before a terminal state is seen
     */
    public function poll(callable $check, callable $isTerminal, PollingPolicy $policy, string $subject): mixed
    {
        $startedAt = $this->clock->now()->getTimestamp() + (int) $this->clock->now()->format('u') / 1_000_000;
        $attempt = 0;

        while (true) {
            ++$attempt;
            $state = $check();
            if ($isTerminal($state)) {
                return $state;
            }

            $elapsed = $this->clock->now()->getTimestamp() + (int) $this->clock->now()->format('u') / 1_000_000 - $startedAt;
            $delay = $policy->delayAfter($attempt);

            if (($policy->maxAttempts !== null && $attempt >= $policy->maxAttempts) || $elapsed + $delay > $policy->timeoutSeconds) {
                throw new PollingTimeoutException(\sprintf(
                    'Gave up waiting for %s after %d attempt(s) and %.1f seconds. The operation may still complete; poll again later.',
                    $subject,
                    $attempt,
                    $elapsed,
                ));
            }

            $this->sleeper->sleep($delay);
        }
    }
}
