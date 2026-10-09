<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use B4x\Ksef\Tests\Support\ClockSleeper;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\MutableClock;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Wires a real {@see KsefClient} to an in-memory KSeF so whole flows run without network access.
 */
abstract class KsefTestCase extends TestCase
{
    protected FakeKsef $ksef;
    protected MutableClock $clock;
    protected ClockSleeper $sleeper;

    /** @var array<string, mixed>|null the decoded open-session request */
    protected ?array $openedSession = null;

    protected function setUp(): void
    {
        $this->ksef = new FakeKsef();
        $this->clock = new MutableClock('2026-06-01T10:00:00+00:00');
        $this->sleeper = new ClockSleeper($this->clock);

        $this->ksef->json('POST', '/auth/challenge', 200, [
            'challenge' => '20260601-CR-ABCDEF0123-0123456789-AB',
            'timestamp' => '2026-06-01T10:00:00+00:00',
            'timestampMs' => 1_780_308_000_000,
            'clientIp' => '127.0.0.1',
        ]);
        $this->ksef->json('POST', '/auth/ksef-token', 202, ['referenceNumber' => 'auth-1', 'authenticationToken' => ['token' => 'temp', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/auth/auth-1', 200, ['startDate' => '2026-06-01T10:00:00+00:00', 'authenticationMethod' => 'Token', 'status' => ['code' => 200, 'description' => 'ok']]);
        $this->ksef->json('POST', '/auth/token/redeem', 200, [
            'accessToken' => ['token' => 'access-1', 'validUntil' => '2026-06-01T10:15:00+00:00'],
            'refreshToken' => ['token' => 'refresh-1', 'validUntil' => '2026-06-08T10:00:00+00:00'],
        ]);
    }

    protected function client(?RetryPolicy $retry = null, ?SubmissionRecoveryPolicy $recovery = null): KsefClient
    {
        return KsefClient::builder()
            ->environment(Environment::Test)
            ->baseUrl('https://api.example.test/v2')
            ->httpClient($this->ksef->http, Http::factory(), Http::factory())
            ->context(ContextIdentifier::nip('5265877635'))
            ->credentials(new KsefTokenCredentials('secret-ksef-token'))
            ->clock($this->clock)
            ->sleeper($this->sleeper)
            ->retryPolicy($retry ?? new RetryPolicy(jitterRatio: 0.0))
            ->submissionRecovery($recovery ?? new SubmissionRecoveryPolicy())
            ->build();
    }

    /** Registers a session that records the open request and answers the standard references. */
    protected function routeSession(string $reference = 'sess-1'): void
    {
        $this->ksef->on('POST', '/sessions/online', function (RequestInterface $request) use ($reference): ResponseInterface {
            $this->openedSession = FakeKsef::body($request);

            return Http::json(201, ['referenceNumber' => $reference, 'validUntil' => '2026-06-01T22:00:00+00:00']);
        });
        $this->ksef->json('POST', '/sessions/online/' . $reference . '/close', 204, []);
    }

    /** Decrypts the invoice contained in a send-invoice request exactly as KSeF would. */
    protected function decryptInvoice(RequestInterface $sendRequest): string
    {
        self::assertNotNull($this->openedSession);
        $encryption = $this->openedSession['encryption'];
        self::assertIsArray($encryption);

        $key = PublicKeyLoader::load($this->ksef->privateKeyPem);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withPadding(RSA::ENCRYPTION_OAEP);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withMGFHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);

        $wrappedKey = $encryption['encryptedSymmetricKey'] ?? null;
        $ivBase64 = $encryption['initializationVector'] ?? null;
        $body = FakeKsef::body($sendRequest);
        $content = $body['encryptedInvoiceContent'] ?? null;
        self::assertIsString($wrappedKey);
        self::assertIsString($ivBase64);
        self::assertIsString($content);

        $aesKey = (string) $key->decrypt((string) base64_decode($wrappedKey, true));
        $decrypted = openssl_decrypt((string) base64_decode($content, true), 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, (string) base64_decode($ivBase64, true));
        self::assertIsString($decrypted);

        return $decrypted;
    }

    /**
     * Unwraps the session AES key with the fake Ministry private key and decrypts one ciphertext.
     *
     * @param array<array-key, mixed> $encryption the `encryption` object of an open-session request
     */
    protected function decryptWithSessionKey(array $encryption, string $cipher): string
    {
        $wrappedKey = $encryption['encryptedSymmetricKey'] ?? null;
        $iv = $encryption['initializationVector'] ?? null;
        self::assertIsString($wrappedKey);
        self::assertIsString($iv);

        $key = PublicKeyLoader::load($this->ksef->privateKeyPem);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withPadding(RSA::ENCRYPTION_OAEP);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withMGFHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);

        $plain = openssl_decrypt($cipher, 'aes-256-cbc', (string) $key->decrypt((string) base64_decode($wrappedKey, true)), OPENSSL_RAW_DATA, (string) base64_decode($iv, true));
        self::assertIsString($plain);

        return $plain;
    }
}
