<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Latarnia;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Latarnia\AvailabilityStatus;
use B4x\Ksef\Latarnia\MessageCategory;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use PHPUnit\Framework\TestCase;

final class LatarniaTest extends TestCase
{
    private const MESSAGE = [
        'id' => 'm1', 'eventId' => 7, 'category' => 'FAILURE', 'type' => 'FAILURE_START', 'title' => 'Awaria', 'text' => 'Trwa awaria.',
        'start' => '2026-06-01T10:00:00+02:00', 'version' => 1, 'published' => '2026-06-01T10:05:00+02:00',
    ];

    public function testStatusAndMessagesAreReadFromTheEnvironmentsHostWithoutCredentials(): void
    {
        $http = (new FakeHttpClient())->route(static fn($request) => str_ends_with($request->getUri()->getPath(), '/status')
            ? Http::json(200, ['status' => 'FAILURE', 'messages' => [self::MESSAGE]])
            : Http::json(200, [self::MESSAGE]));
        $client = $this->client($http, Environment::Test);

        $report = $client->availability();
        self::assertSame(AvailabilityStatus::Failure, $report->status);
        self::assertTrue($report->isFailure());
        self::assertFalse($report->isAvailable());
        self::assertSame(MessageCategory::Failure, $report->messages[0]->category);
        self::assertNull($report->messages[0]->end, 'The end of a failure is unknown at its start.');

        self::assertCount(1, $client->availabilityMessages());
        $request = $http->requests[0];
        self::assertSame('api-latarnia-test.ksef.mf.gov.pl', $request->getUri()->getHost());
        self::assertFalse($request->hasHeader('Authorization'));
    }

    public function testAnAvailableSystemHasNoMessages(): void
    {
        $http = (new FakeHttpClient())->queue(Http::json(200, ['status' => 'AVAILABLE']));

        $report = $this->client($http, Environment::Production)->availability();

        self::assertTrue($report->isAvailable());
        self::assertSame([], $report->messages);
        self::assertSame('api-latarnia.ksef.mf.gov.pl', $http->requests[0]->getUri()->getHost());
    }

    public function testDemoHasNoAvailabilityServiceUnlessOneIsConfigured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(new FakeHttpClient(), Environment::Demo)->availability();
    }

    private function client(FakeHttpClient $http, Environment $environment): KsefClient
    {
        return KsefClient::builder()
            ->environment($environment)
            ->httpClient($http, Http::factory(), Http::factory())
            ->context(ContextIdentifier::nip('5265877635'))
            ->credentials(new KsefTokenCredentials('t'))
            ->build();
    }
}
