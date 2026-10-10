<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Auth;

use B4x\Ksef\Auth\AccessTokenProvider;
use B4x\Ksef\Auth\AuthApi;
use B4x\Ksef\Auth\Authenticator;
use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Crypto\ApiPublicKeyProvider;
use B4x\Ksef\Crypto\KsefTokenEncryptor;
use B4x\Ksef\Exception\AuthenticationException;
use B4x\Ksef\Exception\PollingTimeoutException;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Tests\Support\ClockSleeper;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\MutableClock;
use B4x\Ksef\Tests\Support\TestPki;
use B4x\Ksef\Tests\Support\XmlDsigVerifier;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AuthenticatorTest extends TestCase
{
    private const NIP = '5265877635';

    private FakeKsef $ksef;
    private MutableClock $clock;
    private ClockSleeper $sleeper;

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
    }

    public function testKsefTokenFlowEncryptsTokenWithTimestampAndRedeemsTokens(): void
    {
        $this->routeAuthSuccess('/auth/ksef-token');

        $tokens = $this->authenticator()->authenticate(ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('my-ksef-token'));

        self::assertSame('access-1', $tokens->accessToken->token);

        $submit = $this->ksef->requestsTo('POST', '/auth/ksef-token')[0];
        $body = FakeKsef::body($submit);
        self::assertSame('20260601-CR-ABCDEF0123-0123456789-AB', $body['challenge']);
        self::assertSame(['type' => 'Nip', 'value' => self::NIP], $body['contextIdentifier']);
        self::assertSame($this->ksef->publicKeyId(), $body['publicKeyId']);
        self::assertIsString($body['encryptedToken']);
        self::assertSame('my-ksef-token|1780308000000', $this->decrypt((string) base64_decode($body['encryptedToken'], true)));
        self::assertArrayNotHasKey('authorizationPolicy', $body);

        // Status is checked with the temporary authentication token; so is the redeem call.
        self::assertSame('Bearer temp-token', $this->ksef->requestsTo('GET', '/auth/ref-1')[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer temp-token', $this->ksef->requestsTo('POST', '/auth/token/redeem')[0]->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('my-ksef-token', implode("\n", array_map(static fn(RequestInterface $r): string => (string) $r->getUri(), $this->ksef->requests)));
    }

    public function testCertificateFlowSubmitsAVerifiableSignedDocument(): void
    {
        $this->routeAuthSuccess('/auth/xades-signature');
        $pki = TestPki::personal(self::NIP);

        $this->authenticator()->authenticate(
            ContextIdentifier::nip(self::NIP),
            CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']),
        );

        $submit = $this->ksef->requestsTo('POST', '/auth/xades-signature')[0];
        self::assertSame('application/xml', $submit->getHeaderLine('Content-Type'));
        $xml = (string) $submit->getBody();
        self::assertStringContainsString('<Challenge>20260601-CR-ABCDEF0123-0123456789-AB</Challenge>', $xml);
        self::assertSame([], XmlDsigVerifier::verify($xml));
    }

    public function testPollsWhileVerificationIsInProgress(): void
    {
        $this->ksef->json('POST', '/auth/ksef-token', 202, ['referenceNumber' => 'ref-1', 'authenticationToken' => ['token' => 'temp-token', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(100, 'Authentication in progress'));
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(100, 'Authentication in progress'));
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(200, 'Authentication succeeded'));
        $this->ksef->json('POST', '/auth/token/redeem', 200, $this->tokens());

        $this->authenticator()->authenticate(ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('t'));

        self::assertCount(3, $this->ksef->requestsTo('GET', '/auth/ref-1'));
        self::assertSame([1.0, 1.5], $this->sleeper->sleeps);
    }

    public function testFailureStatusRaisesAuthenticationExceptionWithKsefCode(): void
    {
        $this->ksef->json('POST', '/auth/ksef-token', 202, ['referenceNumber' => 'ref-1', 'authenticationToken' => ['token' => 'temp-token', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(450, 'Authentication failed because of an invalid token', ['Token revoked']));

        try {
            $this->authenticator()->authenticate(ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('t'));
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(450, $e->ksefCode());
            self::assertStringContainsString('Token revoked', $e->getMessage());
            self::assertSame('ref-1', $e->traceId);
        }
        self::assertSame([], $this->ksef->requestsTo('POST', '/auth/token/redeem'), 'Tokens must not be redeemed after a failure.');
    }

    public function testGivesUpWhenVerificationNeverFinishes(): void
    {
        $this->ksef->json('POST', '/auth/ksef-token', 202, ['referenceNumber' => 'ref-1', 'authenticationToken' => ['token' => 'temp-token', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(100, 'Authentication in progress'));

        $this->expectException(PollingTimeoutException::class);
        $this->authenticator(new PollingPolicy(1.0, 5.0, 2.0, 20.0))->authenticate(ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('t'));
    }

    public function testAccessTokenProviderReusesRefreshesAndReauthenticates(): void
    {
        $this->routeAuthSuccess('/auth/ksef-token');
        $this->ksef->json('POST', '/auth/token/refresh', 200, ['accessToken' => ['token' => 'access-2', 'validUntil' => '2026-06-01T11:00:00+00:00']]);
        $api = new AuthApi($this->transport());
        $provider = new AccessTokenProvider($this->authenticator(), $api, ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('t'), $this->clock);

        self::assertSame('access-1', $provider->accessToken());
        self::assertSame('access-1', $provider->accessToken(), 'A valid token is reused.');
        self::assertCount(1, $this->ksef->requestsTo('POST', '/auth/ksef-token'));

        $this->clock->set('2026-06-01T10:14:30+00:00'); // access token (valid until 10:15) is inside the 60 s margin
        self::assertSame('access-2', $provider->accessToken());
        self::assertSame('Bearer refresh-1', $this->ksef->requestsTo('POST', '/auth/token/refresh')[0]->getHeaderLine('Authorization'));

        $provider->invalidate();
        self::assertSame('access-1', $provider->accessToken());
        self::assertCount(2, $this->ksef->requestsTo('POST', '/auth/ksef-token'));
    }

    public function testProviderFallsBackToFullAuthenticationWhenRefreshIsRejected(): void
    {
        $this->routeAuthSuccess('/auth/ksef-token');
        $this->ksef->json('POST', '/auth/token/refresh', 401, ['title' => 'Unauthorized', 'status' => 401, 'detail' => 'Refresh token expired']);
        $provider = new AccessTokenProvider($this->authenticator(), new AuthApi($this->transport()), ContextIdentifier::nip(self::NIP), new KsefTokenCredentials('t'), $this->clock);

        $provider->accessToken();
        $this->clock->set('2026-06-01T10:14:30+00:00');

        self::assertSame('access-1', $provider->accessToken());
        self::assertCount(2, $this->ksef->requestsTo('POST', '/auth/ksef-token'));
    }

    private function routeAuthSuccess(string $submitPath): void
    {
        $this->ksef->json('POST', $submitPath, 202, ['referenceNumber' => 'ref-1', 'authenticationToken' => ['token' => 'temp-token', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/auth/ref-1', 200, $this->authStatus(200, 'Authentication succeeded'));
        $this->ksef->json('POST', '/auth/token/redeem', 200, $this->tokens());
    }

    /**
     * @param list<string> $details
     *
     * @return array<string, mixed>
     */
    private function authStatus(int $code, string $description, array $details = []): array
    {
        return ['startDate' => '2026-06-01T10:00:00+00:00', 'authenticationMethod' => 'Token', 'status' => ['code' => $code, 'description' => $description, 'details' => $details]];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokens(): array
    {
        return [
            'accessToken' => ['token' => 'access-1', 'validUntil' => '2026-06-01T10:15:00+00:00'],
            'refreshToken' => ['token' => 'refresh-1', 'validUntil' => '2026-06-08T10:00:00+00:00'],
        ];
    }

    private function transport(): Transport
    {
        return new Transport('https://api.example.test/v2', $this->ksef->http, Http::factory(), Http::factory(), RetryPolicy::none(), $this->sleeper);
    }

    private function authenticator(?PollingPolicy $polling = null): Authenticator
    {
        $transport = $this->transport();
        $keys = new ApiPublicKeyProvider($transport, $this->clock);

        return new Authenticator(new AuthApi($transport), new KsefTokenEncryptor($keys), new Poller($this->clock, $this->sleeper), $polling ?? new PollingPolicy());
    }

    private function decrypt(string $ciphertext): string
    {
        $key = PublicKeyLoader::load($this->ksef->privateKeyPem);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withPadding(RSA::ENCRYPTION_OAEP);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withMGFHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);

        return (string) $key->decrypt($ciphertext);
    }
}
