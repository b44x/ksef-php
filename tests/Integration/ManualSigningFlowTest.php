<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\InMemoryTokenStore;
use B4x\Ksef\Auth\ManualCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ManualSignatureRequiredException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Signing\OpenSslXadesSigner;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\TestPki;

final class ManualSigningFlowTest extends KsefTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ksef->json('POST', '/auth/xades-signature', 202, ['referenceNumber' => 'auth-1', 'authenticationToken' => ['token' => 'temp', 'validUntil' => '2026-06-01T10:10:00+00:00']]);
        $this->ksef->json('GET', '/rate-limits', 200, []);
    }

    public function testThePersonSignsOnceAndLaterRequestsReuseTheTokens(): void
    {
        $store = new InMemoryTokenStore($this->clock);
        $first = $this->manualClient($store);

        try {
            $first->rateLimits();
            self::fail('Nobody has signed yet.');
        } catch (ManualSignatureRequiredException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame([], $this->ksef->requestsTo('POST', '/auth/challenge'), 'No pointless challenge is fetched.');
        }

        $request = $first->createAuthenticationRequest();
        self::assertStringContainsString('<Challenge>20260601-CR-ABCDEF0123-0123456789-AB</Challenge>', $request->xml);
        self::assertSame('20260601-CR-ABCDEF0123-0123456789-AB', $request->challenge);
        self::assertSame('2026-06-01T10:10:00+00:00', $request->expiresAt->format('c'));

        $first->authenticateWithSignedRequest($this->sign($request->xml));
        $first->rateLimits();
        self::assertSame('Bearer access-1', $this->ksef->requestsTo('GET', '/rate-limits')[0]->getHeaderLine('Authorization'));

        // A later PHP request: a new client, nobody signs, the tokens come from the store.
        $second = $this->manualClient($store);
        $second->rateLimits();
        self::assertCount(1, $this->ksef->requestsTo('POST', '/auth/xades-signature'));
        self::assertCount(2, $this->ksef->requestsTo('GET', '/rate-limits'));
    }

    public function testADocumentThatIsNotASignedRequestForThisContextIsRefused(): void
    {
        $client = $this->manualClient(new InMemoryTokenStore($this->clock));
        $request = $client->createAuthenticationRequest();

        $cases = [
            'unsigned' => $request->xml,
            'not xml' => 'nope',
            'other root' => '<x/>',
            'other context' => str_replace('5265877635', '1234563218', $this->sign($request->xml)),
        ];
        foreach ($cases as $label => $xml) {
            try {
                $client->authenticateWithSignedRequest($xml);
                self::fail('Expected a ValidationException for: ' . $label);
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->ksef->requestsTo('POST', '/auth/xades-signature'), 'Nothing wrong reaches KSeF.');
    }

    public function testManualSigningNeedsManualCredentials(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client()->createAuthenticationRequest();
    }

    private function manualClient(InMemoryTokenStore $store): KsefClient
    {
        return KsefClient::builder()
            ->environment(Environment::Test)
            ->baseUrl('https://api.example.test/v2')
            ->httpClient($this->ksef->http, Http::factory(), Http::factory())
            ->context(ContextIdentifier::nip('5265877635'))
            ->credentials(new ManualCredentials())
            ->clock($this->clock)
            ->sleeper($this->sleeper)
            ->retryPolicy(new RetryPolicy(jitterRatio: 0.0))
            ->tokenStore($store)
            ->build();
    }

    /** Stands in for the external signer (a trusted profile, a qualified signature app). */
    private function sign(string $xml): string
    {
        $pki = TestPki::personal('1234567890');

        return (new OpenSslXadesSigner($pki['certificatePem'], $pki['privateKeyPem'], null, $this->clock))->sign($xml);
    }
}
