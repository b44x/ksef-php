<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Http;

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\AuthenticationException;
use B4x\Ksef\Exception\AuthorizationException;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Exception\RateLimitException;
use B4x\Ksef\Exception\ServerException;
use B4x\Ksef\Exception\TransportException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class TransportTest extends TestCase
{
    private FakeHttpClient $client;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->client = new FakeHttpClient();
        $this->sleeper = new RecordingSleeper();
    }

    public function testBuildsRequestWithHeadersAuthorizationAndJsonBody(): void
    {
        $this->client->queue(Http::json(202, ['referenceNumber' => 'abc']));

        $response = $this->transport()->send(ApiRequest::post('/sessions/online', ['a' => 'zażółć'], 'secret-token', query: ['x' => 'y z']));

        $request = $this->client->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test/v2/sessions/online?x=y%20z', (string) $request->getUri());
        self::assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        self::assertSame('problem-details', $request->getHeaderLine('X-Error-Format'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"a":"zażółć"}', (string) $request->getBody());
        self::assertSame(['referenceNumber' => 'abc'], $response->json());
    }

    public function testRetriesSafeRequestsOnServerErrorsWithBackoff(): void
    {
        $this->client->queue(Http::json(503, []), Http::json(500, []), Http::json(200, ['ok' => true]));

        $response = $this->transport(new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 1.0, jitterRatio: 0.0))
            ->send(ApiRequest::get('/limits/context', 't'));

        self::assertSame(200, $response->status);
        self::assertSame([1.0, 2.0], $this->sleeper->sleeps);
        self::assertCount(3, $this->client->requests);
    }

    public function testRetriesSafeRequestsOnNetworkErrors(): void
    {
        $this->client->queue(Http::networkError(), Http::json(200, ['ok' => true]));

        self::assertSame(200, $this->transport()->send(ApiRequest::get('/x'))->status);
        self::assertCount(2, $this->client->requests);
    }

    public function testNetworkErrorsOnMutatingRequestsAreNeverRetried(): void
    {
        $this->client->queue(Http::networkError('timeout'), Http::json(202, []));

        try {
            $this->transport()->send(ApiRequest::post('/sessions/online/x/invoices', ['a' => 1], 't'));
            self::fail('Expected a TransportException.');
        } catch (TransportException $e) {
            self::assertStringContainsString('timeout', $e->getMessage());
        }
        self::assertCount(1, $this->client->requests);
    }

    public function testServerErrorsOnMutatingRequestsAreNeverRetried(): void
    {
        $this->client->queue(Http::json(503, []), Http::json(202, []));

        $this->expectException(ServerException::class);
        try {
            $this->transport()->send(ApiRequest::post('/sessions/online/x/invoices', ['a' => 1], 't'));
        } finally {
            self::assertCount(1, $this->client->requests);
        }
    }

    public function testRateLimitedMutatingRequestsAreRetriedAfterRetryAfter(): void
    {
        $this->client->queue(
            Http::json(429, ['title' => 'Too Many Requests'], ['Retry-After' => '7']),
            Http::json(202, ['referenceNumber' => 'r']),
        );

        $response = $this->transport()->send(ApiRequest::post('/sessions/online/x/invoices', ['a' => 1], 't'));

        self::assertSame(202, $response->status);
        self::assertSame([7.0], $this->sleeper->sleeps);
    }

    public function testRateLimitExhaustionRaisesRateLimitException(): void
    {
        $this->client->queue(
            Http::json(429, ['detail' => 'slow down'], ['Retry-After' => '3']),
            Http::json(429, ['detail' => 'slow down'], ['Retry-After' => '3']),
        );

        try {
            $this->transport(new RetryPolicy(maxAttempts: 2, jitterRatio: 0.0))->send(ApiRequest::get('/x'));
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(3, $e->retryAfterSeconds);
            self::assertSame(429, $e->httpStatus);
            self::assertTrue($e->isRetryable());
        }
    }

    public function testDoesNotWaitForRetryAfterBeyondTheConfiguredCap(): void
    {
        $this->client->queue(Http::json(429, [], ['Retry-After' => '3600']));

        $this->expectException(RateLimitException::class);
        try {
            $this->transport(new RetryPolicy(maxAttempts: 3, maxDelaySeconds: 30.0))->send(ApiRequest::get('/x'));
        } finally {
            self::assertSame([], $this->sleeper->sleeps);
        }
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('errorStatuses')]
    public function testMapsErrorStatusesToExceptions(int $status, string $expected): void
    {
        $this->client->queue(Http::json($status, ['title' => 'x']));

        $this->expectException($expected);
        $this->transport(RetryPolicy::none())->send(ApiRequest::get('/x'));
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function errorStatuses(): iterable
    {
        yield '400' => [400, ApiException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, AuthorizationException::class];
        yield '404' => [404, ApiException::class];
        yield '500' => [500, ServerException::class];
    }

    public function testParsesProblemDetailsErrors(): void
    {
        $this->client->queue(Http::json(400, [
            'title' => 'Bad Request',
            'status' => 400,
            'detail' => 'Validation failed',
            'traceId' => 'trace-1',
            'errors' => [['code' => 21405, 'description' => 'Invalid input', 'details' => ['field a']]],
        ]));

        try {
            $this->transport(RetryPolicy::none())->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame(21405, $e->ksefCode());
            self::assertSame('trace-1', $e->traceId);
            self::assertSame(['field a'], $e->errors[0]->details);
            self::assertStringContainsString('[21405] Invalid input', $e->getMessage());
        }
    }

    public function testParsesLegacyExceptionEnvelopeAndForbiddenReason(): void
    {
        $this->client->queue(
            Http::json(400, ['exception' => ['referenceNumber' => 'ref-9', 'exceptionDetailList' => [['exceptionCode' => 21111, 'exceptionDescription' => 'Invalid challenge']]]]),
            Http::json(403, ['title' => 'Forbidden', 'detail' => 'No access', 'reasonCode' => 'ip-not-allowed']),
        );
        $transport = $this->transport(RetryPolicy::none());

        try {
            $transport->send(ApiRequest::get('/a'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(21111, $e->ksefCode());
            self::assertSame('ref-9', $e->traceId);
        }

        try {
            $transport->send(ApiRequest::get('/b'));
            self::fail('Expected AuthorizationException');
        } catch (AuthorizationException $e) {
            self::assertSame('ip-not-allowed', $e->reasonCode);
        }
    }

    public function testErrorBodiesThatAreNotJsonStillYieldAnApiException(): void
    {
        $this->client->queue(Http::raw(502, '<html>bad gateway</html>'));

        $this->expectException(ServerException::class);
        $this->transport(RetryPolicy::none())->send(ApiRequest::get('/x'));
    }

    public function testMalformedSuccessBodyIsReported(): void
    {
        $this->client->queue(Http::raw(200, 'not json'));
        $response = $this->transport()->send(ApiRequest::get('/x'));

        $this->expectException(MalformedResponseException::class);
        $response->json();
    }

    public function testRejectsPlainHttpBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        new Transport('http://api.example.test/v2', $this->client, Http::factory(), Http::factory());
    }

    public function testDownloadSendsNoCredentialsAndRequiresHttps(): void
    {
        $this->client->queue(Http::raw(200, '<Upo/>', ['x-ms-meta-hash' => 'abc=']));
        $transport = $this->transport();

        $response = $transport->download('https://files.example.test/upo?sig=1');

        self::assertSame('<Upo/>', $response->body);
        self::assertSame('abc=', $response->header('X-MS-Meta-Hash'));
        self::assertFalse($this->client->lastRequest()->hasHeader('Authorization'));

        $this->expectException(ConfigurationException::class);
        $transport->download('http://files.example.test/upo');
    }

    public function testNeverLogsTokensOrBodies(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };
        $this->client->queue(Http::networkError('boom'), Http::json(200, ['secret' => 'body-secret']));
        $transport = new Transport('https://api.example.test/v2', $this->client, Http::factory(), Http::factory(), new RetryPolicy(jitterRatio: 0.0), $this->sleeper, $logger);

        $transport->send(ApiRequest::get('/x', 'super-secret-token'));

        $log = implode("\n", $logger->lines);
        self::assertStringNotContainsString('super-secret-token', $log);
        self::assertStringNotContainsString('body-secret', $log);
        self::assertStringContainsString('GET', $log);
    }

    private function transport(?RetryPolicy $policy = null): Transport
    {
        return new Transport(
            'https://api.example.test/v2',
            $this->client,
            Http::factory(),
            Http::factory(),
            $policy ?? new RetryPolicy(jitterRatio: 0.0),
            $this->sleeper,
        );
    }
}
