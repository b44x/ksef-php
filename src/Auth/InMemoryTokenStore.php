<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use Psr\Clock\ClockInterface;

/** Keeps tokens for the lifetime of the object: for tests and long-running workers that share one store. */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, array{string, int}> */
    private array $items = [];

    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function get(string $key): ?string
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        [$value, $expiresAt] = $this->items[$key];
        if ($expiresAt <= $this->now()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, $this->now() + $ttlSeconds];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
