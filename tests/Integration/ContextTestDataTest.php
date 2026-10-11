<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Limits\RateLimit;
use B4x\Ksef\Limits\SessionLimits;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Http;
use DateTimeImmutable;

final class ContextTestDataTest extends KsefTestCase
{
    private const GROUPS = ['onlineSession', 'onlineSessionClose', 'batchSession', 'batchSessionClose', 'invoiceSend', 'invoiceStatus', 'sessionList', 'sessionInvoiceList', 'sessionMisc', 'invoiceMetadata', 'invoiceExport', 'invoiceExportStatus', 'invoiceDownload', 'collectiveIdentifier', 'other', 'anonymous', 'global'];

    public function testRateLimitOverridesAreMergedIntoTheCurrentLimitsAndSentWithTheDocumentedGroups(): void
    {
        $current = [];
        foreach (self::GROUPS as $group) {
            $current[$group] = ['perSecond' => 10, 'perMinute' => 30, 'perHour' => 180];
        }
        $this->ksef->json('GET', '/rate-limits', 200, $current);
        $this->ksef->json('POST', '/testdata/rate-limits', 200, []);

        $this->client()->testData()->setRateLimits(['invoiceSend' => new RateLimit(1, 2, 3)]);

        $body = FakeKsef::body($this->ksef->requestsTo('POST', '/testdata/rate-limits')[0]);
        self::assertIsArray($body['rateLimits']);
        self::assertSame(['perSecond' => 1, 'perMinute' => 2, 'perHour' => 3], $body['rateLimits']['invoiceSend']);
        self::assertSame(['perSecond' => 10, 'perMinute' => 30, 'perHour' => 180], $body['rateLimits']['invoiceStatus']);
        self::assertCount(13, $body['rateLimits'], 'Only the 13 groups of the request schema, not anonymous/global/*Close.');
        self::assertSame('Bearer access-1', $this->ksef->requestsTo('POST', '/testdata/rate-limits')[0]->getHeaderLine('Authorization'));
    }

    public function testUnknownRateLimitGroupsAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->client()->testData()->setRateLimits(['nonsense' => new RateLimit(1, 2, 3)]);
    }

    public function testSessionAndCertificateLimitsAndResets(): void
    {
        foreach (['POST', 'DELETE'] as $method) {
            $this->ksef->json($method, '/testdata/limits/context/session', 200, []);
            $this->ksef->json($method, '/testdata/limits/subject/certificate', 200, []);
        }
        $this->ksef->json('DELETE', '/testdata/rate-limits', 200, []);
        $this->ksef->json('POST', '/testdata/rate-limits/production', 200, []);
        $this->ksef->json('PUT', '/testdata/certificates/0123ABCD', 200, []);
        $test = $this->client()->testData();

        $test->setSessionLimits(new SessionLimits(5, 1, 2), new SessionLimits(7, 3, 4), 10);
        $test->resetSessionLimits();
        $test->setCertificateLimits(3, null);
        $test->resetCertificateLimits();
        $test->resetRateLimits();
        $test->useProductionRateLimits();
        $test->setCertificateValidity('0123ABCD', new DateTimeImmutable('2026-12-31T00:00:00+00:00'));

        self::assertSame(
            ['onlineSession' => ['maxInvoices' => 5, 'maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 2], 'batchSession' => ['maxInvoices' => 7, 'maxInvoiceSizeInMB' => 3, 'maxInvoiceWithAttachmentSizeInMB' => 4], 'collectiveIdentifier' => ['maxInvoices' => 10]],
            FakeKsef::body($this->ksef->requestsTo('POST', '/testdata/limits/context/session')[0]),
        );
        self::assertSame(['subjectIdentifierType' => 'Nip', 'enrollment' => ['maxEnrollments' => 3], 'certificate' => ['maxCertificates' => null]], FakeKsef::body($this->ksef->requestsTo('POST', '/testdata/limits/subject/certificate')[0]));
        self::assertSame(['validTo' => '2026-12-31T00:00:00+00:00'], FakeKsef::body($this->ksef->requestsTo('PUT', '/testdata/certificates/0123ABCD')[0]));
    }

    public function testItExistsOnTheTestEnvironmentOnly(): void
    {
        $production = KsefClient::builder()
            ->environment(Environment::Production)
            ->httpClient($this->ksef->http, Http::factory(), Http::factory())
            ->context(ContextIdentifier::nip('5265877635'))
            ->credentials(new KsefTokenCredentials('t'))
            ->build();

        $this->expectException(ConfigurationException::class);
        $production->testData();
    }
}
