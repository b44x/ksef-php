<?php

declare(strict_types=1);

namespace Ksef\Tests\Unit\Crypto;

use Ksef\Crypto\ApiPublicKeyProvider;
use Ksef\Crypto\KeyUsage;
use Ksef\Exception\EncryptionException;
use Ksef\Http\RetryPolicy;
use Ksef\Http\Transport;
use Ksef\Tests\Support\FakeHttpClient;
use Ksef\Tests\Support\Http;
use Ksef\Tests\Support\MutableClock;
use Ksef\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class ApiPublicKeyProviderTest extends TestCase
{
    public function testSelectsTheNewestValidKeyForTheUsageAndCachesIt(): void
    {
        $clock = new MutableClock('2026-06-01T10:00:00+00:00');
        $client = (new FakeHttpClient())->queue(Http::json(200, [
            $this->entry('old', '2025-01-01', '2027-01-01', 'SymmetricKeyEncryption'),
            $this->entry('new', '2026-05-01', '2028-01-01', 'SymmetricKeyEncryption'),
            $this->entry('token', '2025-01-01', '2027-01-01', 'KsefTokenEncryption'),
            $this->entry('future', '2026-07-01', '2029-01-01', 'SymmetricKeyEncryption'),
        ]));
        $provider = $this->provider($client, $clock);

        self::assertSame('new', $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);
        self::assertSame('token', $provider->get(KeyUsage::KsefTokenEncryption)->publicKeyId);
        self::assertCount(1, $client->requests, 'The key list must be cached.');
        self::assertFalse($client->lastRequest()->hasHeader('Authorization'), 'Public keys are fetched anonymously.');
    }

    public function testRefreshesAfterTtlAndWhenCachedKeysExpire(): void
    {
        $clock = new MutableClock('2026-06-01T10:00:00+00:00');
        $client = (new FakeHttpClient())->queue(
            Http::json(200, [$this->entry('a', '2025-01-01', '2026-06-02', 'SymmetricKeyEncryption')]),
            Http::json(200, [$this->entry('b', '2026-05-30', '2028-01-01', 'SymmetricKeyEncryption')]),
        );
        $provider = $this->provider($client, $clock);

        self::assertSame('a', $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);

        $clock->set('2026-06-02T10:00:00+00:00');
        // Cached key 'a' expired, so a refresh is triggered.
        self::assertSame('b', $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);
        self::assertCount(2, $client->requests);
    }

    public function testFailsWhenNoValidKeyExists(): void
    {
        $client = (new FakeHttpClient())->queue(
            Http::json(200, [$this->entry('x', '2020-01-01', '2021-01-01', 'SymmetricKeyEncryption')]),
            Http::json(200, [$this->entry('x', '2020-01-01', '2021-01-01', 'SymmetricKeyEncryption')]),
        );

        $this->expectException(EncryptionException::class);
        $this->provider($client, new MutableClock('2026-06-01T10:00:00+00:00'))->get(KeyUsage::SymmetricKeyEncryption);
    }

    private function provider(FakeHttpClient $client, MutableClock $clock): ApiPublicKeyProvider
    {
        $transport = new Transport('https://api.example.test/v2', $client, Http::factory(), Http::factory(), RetryPolicy::none());

        return new ApiPublicKeyProvider($transport, $clock, 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $id, string $from, string $to, string $usage): array
    {
        return [
            'certificate' => base64_encode(TestPki::selfSigned()['certificateDer']),
            'certificateId' => 'cert-' . $id,
            'publicKeyId' => $id,
            'validFrom' => $from . 'T00:00:00+00:00',
            'validTo' => $to . 'T00:00:00+00:00',
            'usage' => [$usage],
        ];
    }
}
