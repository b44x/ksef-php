<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Auth;

use B4x\Ksef\Auth\FileTokenStore;
use B4x\Ksef\Auth\InMemoryTokenStore;
use B4x\Ksef\Auth\Psr16TokenStore;
use B4x\Ksef\Exception\ConfigurationException;
use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class TokenStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ksef-token-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFileStoreRoundTripsWithOwnerOnlyPermissions(): void
    {
        $store = new FileTokenStore($this->directory);
        $key = hash('sha256', 'k');

        self::assertNull($store->get($key));
        $store->set($key, 'secret-value', 60);
        self::assertSame('secret-value', $store->get($key));

        $file = $this->directory . '/ksef-' . $key . '.json';
        self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->directory)), -4));

        $store->delete($key);
        self::assertNull($store->get($key));
    }

    public function testFileStoreDropsExpiredAndRefusesKeysThatCouldEscapeTheDirectory(): void
    {
        $store = new FileTokenStore($this->directory);
        $key = hash('sha256', 'k');
        $store->set($key, 'v', -5);
        self::assertNull($store->get($key));
        self::assertFileDoesNotExist($this->directory . '/ksef-' . $key . '.json');

        $this->expectException(ConfigurationException::class);
        $store->get('../../etc/passwd');
    }

    public function testInMemoryStoreExpiresEntries(): void
    {
        $store = new InMemoryTokenStore();
        $store->set('k', 'v', 60);
        self::assertSame('v', $store->get('k'));
        $store->set('k', 'v', -1);
        self::assertNull($store->get('k'));
    }

    public function testPsr16AdapterPrefixesKeysAndPassesTheTtl(): void
    {
        $cache = new class implements CacheInterface {
            /** @var array<string, array{mixed, int|null}> */
            public array $items = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key][0] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->items[$key] = [$value, \is_int($ttl) ? $ttl : null];

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->items[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->items = [];

                return true;
            }

            /**
             * @param iterable<string> $keys
             *
             * @return iterable<string, mixed>
             */
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            /** @param iterable<mixed, mixed> $values */
            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            /** @param iterable<string> $keys */
            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->items[$key]);
            }
        };
        $store = new Psr16TokenStore($cache, 'app.');

        $store->set('abc', 'value', 90);
        self::assertSame(['app.abc' => ['value', 90]], $cache->items);
        self::assertSame('value', $store->get('abc'));
        $store->delete('abc');
        self::assertNull($store->get('abc'));
    }
}
