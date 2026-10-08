<?php

declare(strict_types=1);

namespace Ksef\Http;

use JsonException;
use Ksef\Exception\ConfigurationException;
use Ksef\Exception\SerializationException;
use Ksef\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes {@see ApiRequest}s over any PSR-18 client.
 *
 * Responsibilities: URL and header assembly, JSON encoding, retry/backoff, error mapping and
 * secret-free logging. It contains no KSeF business logic.
 *
 * Timeouts and TLS verification are properties of the injected PSR-18 client. Configure them there
 * (for Guzzle: `timeout`, `connect_timeout`; TLS verification must stay enabled).
 */
final class Transport
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly Sleeper $sleeper = new NativeSleeper(),
        ?LoggerInterface $logger = null,
        private readonly string $userAgent = 'ksef-php',
        private readonly ErrorResponseParser $errorParser = new ErrorResponseParser(),
    ) {
        if (!str_starts_with($baseUrl, 'https://') && !str_starts_with($baseUrl, 'http://localhost') && !str_starts_with($baseUrl, 'http://127.0.0.1')) {
            throw new ConfigurationException('The KSeF base URL must use https.');
        }

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws \Ksef\Exception\ApiException on an HTTP error status
     * @throws TransportException when no usable response was received
     */
    public function send(ApiRequest $request): ApiResponse
    {
        $psrRequest = $this->buildRequest($request);

        return $this->execute($psrRequest, $request->retry, $request->method . ' ' . $request->path);
    }

    /**
     * Downloads a pre-signed absolute URL (for example a UPO link) without any credentials.
     *
     * KSeF explicitly states that these links must be fetched without the access token.
     */
    public function download(string $absoluteUrl, string $accept = 'application/xml'): ApiResponse
    {
        if (!str_starts_with($absoluteUrl, 'https://')) {
            throw new ConfigurationException('Refusing to download from a non-https URL.');
        }

        $request = $this->requestFactory->createRequest('GET', $absoluteUrl)
            ->withHeader('Accept', $accept)
            ->withHeader('User-Agent', $this->userAgent);

        return $this->execute($request, RetryMode::Safe, 'GET <pre-signed download url>');
    }

    private function buildRequest(ApiRequest $request): RequestInterface
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($request->path, '/');
        if ($request->query !== []) {
            $url .= '?' . http_build_query(array_filter($request->query, static fn($v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);
        }

        $psr = $this->requestFactory->createRequest($request->method, $url)
            ->withHeader('Accept', $request->accept)
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader('X-Error-Format', 'problem-details');

        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->bearerToken !== null) {
            $psr = $psr->withHeader('Authorization', 'Bearer ' . $request->bearerToken);
        }

        if ($request->body !== null) {
            $payload = \is_string($request->body) ? $request->body : $this->encodeJson($request->body);
            $psr = $psr
                ->withHeader('Content-Type', $request->contentType)
                ->withBody($this->streamFactory->createStream($payload));
        }

        return $psr;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function encodeJson(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new SerializationException('The request payload cannot be encoded as JSON.', [], $e);
        }
    }

    private function execute(RequestInterface $request, RetryMode $mode, string $label): ApiResponse
    {
        $attempt = 1;

        while (true) {
            $startedAt = microtime(true);
            $request = $this->rewindBody($request);

            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $this->logger->warning('KSeF request failed at transport level.', [
                    'request' => $label,
                    'attempt' => $attempt,
                    'error' => $e::class,
                ]);

                if ($mode === RetryMode::Safe && $attempt < $this->retryPolicy->maxAttempts) {
                    $this->wait($attempt, null);
                    ++$attempt;

                    continue;
                }

                throw new TransportException(\sprintf('HTTP transport failure for %s: %s', $label, $e->getMessage()), 0, $e);
            }

            $status = $response->getStatusCode();
            $this->logger->debug('KSeF response received.', [
                'request' => $label,
                'status' => $status,
                'attempt' => $attempt,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_id' => $response->hasHeader('X-Request-Id') ? $response->getHeaderLine('X-Request-Id') : null,
            ]);

            if ($status >= 200 && $status < 300) {
                return $this->toApiResponse($response);
            }

            $retryAfter = $this->retryAfter($response);
            $error = $this->errorParser->parse($status, (string) $response->getBody(), $retryAfter);

            if ($attempt < $this->retryPolicy->maxAttempts && $this->shouldRetry($mode, $status)) {
                $delay = $this->retryPolicy->delayBefore($attempt, $status === 429 ? $retryAfter : null);
                if ($delay !== null) {
                    $this->logger->notice('Retrying KSeF request.', ['request' => $label, 'status' => $status, 'attempt' => $attempt, 'delay_s' => $delay]);
                    $this->sleeper->sleep($delay);
                    ++$attempt;

                    continue;
                }
            }

            throw $error;
        }
    }

    private function shouldRetry(RetryMode $mode, int $status): bool
    {
        return match ($mode) {
            RetryMode::Never => false,
            RetryMode::RateLimitOnly => $status === 429,
            RetryMode::Safe => $status === 429 || \in_array($status, [500, 502, 503, 504], true),
        };
    }

    private function wait(int $attempt, ?int $retryAfter): void
    {
        $delay = $this->retryPolicy->delayBefore($attempt, $retryAfter);
        if ($delay !== null) {
            $this->sleeper->sleep($delay);
        }
    }

    private function rewindBody(RequestInterface $request): RequestInterface
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request;
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        return ctype_digit($value) ? (int) $value : null;
    }

    private function toApiResponse(ResponseInterface $response): ApiResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new ApiResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
