<?php

declare(strict_types=1);

namespace Ksef\Tests\Unit\Polling;

use Ksef\Exception\ConfigurationException;
use Ksef\Exception\PollingTimeoutException;
use Ksef\Http\RetryPolicy;
use Ksef\Polling\Poller;
use Ksef\Polling\PollingPolicy;
use Ksef\Tests\Support\ClockSleeper;
use Ksef\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class PollerTest extends TestCase
{
    public function testReturnsTheFirstTerminalStateAndBacksOff(): void
    {
        $clock = new MutableClock();
        $sleeper = new ClockSleeper($clock);
        /** @var list<int> $states */
        $states = [1, 1, 1, 2];
        $calls = 0;

        $result = (new Poller($clock, $sleeper))->poll(
            static function () use (&$states, &$calls): int {
                ++$calls;

                return (int) array_shift($states);
            },
            static fn(int $state): bool => $state === 2,
            new PollingPolicy(1.0, 3.0, 2.0, 60.0),
            'test',
        );

        self::assertSame(2, $result);
        self::assertSame(4, $calls);
        self::assertSame([1.0, 2.0, 3.0], $sleeper->sleeps, 'delays double and are capped at the maximum');
    }

    public function testTimeoutIsEnforcedByElapsedTime(): void
    {
        $clock = new MutableClock();
        $poller = new Poller($clock, new ClockSleeper($clock));

        try {
            $poller->poll(static fn(): int => 1, static fn(): bool => false, new PollingPolicy(5.0, 5.0, 1.0, 12.0), 'the thing');
            self::fail('Expected PollingTimeoutException');
        } catch (PollingTimeoutException $e) {
            self::assertStringContainsString('the thing', $e->getMessage());
        }
    }

    public function testAttemptLimitAndSingleShotPolicy(): void
    {
        $clock = new MutableClock();
        $calls = 0;
        $poller = new Poller($clock, new ClockSleeper($clock));

        try {
            $poller->poll(static function () use (&$calls): string {
                ++$calls;

                return 'pending';
            }, static fn(): bool => false, PollingPolicy::once(), 'x');
            self::fail('Expected PollingTimeoutException');
        } catch (PollingTimeoutException) {
            self::assertSame(1, $calls);
        }
    }

    public function testRejectsNonsensicalPolicies(): void
    {
        $this->expectException(ConfigurationException::class);
        new PollingPolicy(10.0, 1.0);
    }

    public function testRetryPolicyHonoursRetryAfterWithinTheCapAndRejectsInvalidSettings(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4, baseDelaySeconds: 1.0, maxDelaySeconds: 10.0, jitterRatio: 0.0);

        self::assertSame(1.0, $policy->delayBefore(1));
        self::assertSame(4.0, $policy->delayBefore(3));
        self::assertSame(10.0, $policy->delayBefore(9), 'exponential growth is capped');
        self::assertSame(7.0, $policy->delayBefore(1, 7));
        self::assertNull($policy->delayBefore(1, 11), 'a Retry-After above the cap is not waited for');

        $this->expectException(ConfigurationException::class);
        new RetryPolicy(maxAttempts: 0);
    }
}
