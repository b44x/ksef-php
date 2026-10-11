<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use Psr\SimpleCache\CacheInterface;

/**
 * Keeps tokens in any PSR-16 cache (Redis, APCu, Symfony or Laravel cache, ...). Requires `psr/simple-cache`.
 *
 * The cache must be private to your application: the stored value is a secret.
 */
final class Psr16TokenStore implements TokenStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'ksef.',
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->cache->get($this->prefix . $key);

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->cache->set($this->prefix . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->prefix . $key);
    }
}
