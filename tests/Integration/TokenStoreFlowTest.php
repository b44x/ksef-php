<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\InMemoryTokenStore;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Tests\Support\Http;

final class TokenStoreFlowTest extends KsefTestCase
{
    public function testSeparateClientsSharingAStoreAuthenticateOnce(): void
    {
        $store = new InMemoryTokenStore($this->clock);
        $this->ksef->json('GET', '/limits/context', 200, ['onlineSession' => ['limits' => ['maxInvoices' => 1, 'maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 1]], 'batchSession' => ['limits' => ['maxInvoices' => 1, 'maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 1]]]);
        $this->ksef->json('GET', '/rate-limits', 200, []);

        foreach ([1, 2, 3] as $_) {
            // Every iteration is a "new PHP request": a new client with empty memory.
            $this->clientWithStore($store, 'secret-ksef-token')->rateLimits();
        }
        self::assertCount(1, $this->ksef->requestsTo('POST', '/auth/ksef-token'));

        $this->clientWithStore($store, 'another-token')->rateLimits();
        self::assertCount(2, $this->ksef->requestsTo('POST', '/auth/ksef-token'), 'Other credentials never reuse someone else\'s tokens.');
    }

    private function clientWithStore(InMemoryTokenStore $store, string $token): KsefClient
    {
        return KsefClient::builder()
            ->environment(Environment::Test)
            ->baseUrl('https://api.example.test/v2')
            ->httpClient($this->ksef->http, Http::factory(), Http::factory())
            ->context(ContextIdentifier::nip('5265877635'))
            ->credentials(new KsefTokenCredentials($token))
            ->clock($this->clock)
            ->sleeper($this->sleeper)
            ->retryPolicy(new RetryPolicy(jitterRatio: 0.0))
            ->tokenStore($store)
            ->build();
    }
}
