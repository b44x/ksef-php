<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Crypto;

use B4x\Ksef\Crypto\ApiPublicKeyProvider;
use B4x\Ksef\Crypto\KeyUsage;
use B4x\Ksef\Exception\EncryptionException;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\MutableClock;
use B4x\Ksef\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class ApiPublicKeyProviderTest extends TestCase
{
    /** @var array<string, string> label => the real publicKeyId of its certificate */
    private array $ids = [];

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

        self::assertSame($this->ids['new'], $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);
        self::assertSame($this->ids['token'], $provider->get(KeyUsage::KsefTokenEncryption)->publicKeyId);
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

        self::assertSame($this->ids['a'], $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);

        $clock->set('2026-06-02T10:00:00+00:00');
        // Cached key 'a' expired, so a refresh is triggered.
        self::assertSame($this->ids['b'], $provider->get(KeyUsage::SymmetricKeyEncryption)->publicKeyId);
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

    public function testAListingWhoseKeyIdDoesNotMatchItsCertificateIsRefused(): void
    {
        $entry = $this->entry('x', '2025-01-01', '2027-01-01', 'SymmetricKeyEncryption');
        $entry['publicKeyId'] = 'bm90LXRoZS1oYXNoLW9mLXRoZS1rZXk='; // not the hash of this certificate
        $client = (new FakeHttpClient())->queue(Http::json(200, [$entry]));

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('does not match its publicKeyId');
        $this->provider($client, new MutableClock('2026-06-01T10:00:00+00:00'))->get(KeyUsage::SymmetricKeyEncryption);
    }

    public function testAListWithoutAUsableKeyIsNotRefetchedOnEveryCall(): void
    {
        $client = (new FakeHttpClient())->queue(
            Http::json(200, [$this->entry('x', '2020-01-01', '2021-01-01', 'SymmetricKeyEncryption')]),
            Http::json(200, [$this->entry('x', '2020-01-01', '2021-01-01', 'SymmetricKeyEncryption')]),
        );
        $provider = $this->provider($client, new MutableClock('2026-06-01T10:00:00+00:00'));

        for ($i = 0; $i < 3; ++$i) {
            try {
                $provider->get(KeyUsage::SymmetricKeyEncryption);
            } catch (EncryptionException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertCount(1, $client->requests);
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
        $der = TestPki::selfSigned()['certificateDer'];
        $this->ids[$id] = TestPki::publicKeyId($der);

        return [
            'certificate' => base64_encode($der),
            'certificateId' => 'cert-' . $id,
            'publicKeyId' => $this->ids[$id],
            'validFrom' => $from . 'T00:00:00+00:00',
            'validTo' => $to . 'T00:00:00+00:00',
            'usage' => [$usage],
        ];
    }
}
