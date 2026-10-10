<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Exception\TransportException;
use JsonException;
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
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class Transport
{
    /** Largest accepted API (JSON/XML) response body. */
    private const MAX_API_BODY_BYTES = 32 * 1024 * 1024;

    /** Largest accepted download: an export or batch part is at most 50 MB, plus encryption padding. */
    private const MAX_DOWNLOAD_BYTES = 64 * 1024 * 1024;

    /** Error bodies are small; anything beyond this is cut off before parsing. */
    private const MAX_ERROR_BODY_BYTES = 1024 * 1024;

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
        $parts = parse_url($baseUrl);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if ($scheme !== 'https' && !($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new ConfigurationException('The KSeF base URL must use https.');
        }

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws \B4x\Ksef\Exception\ApiException on an HTTP error status
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

        return $this->execute($request, RetryMode::Safe, 'GET <pre-signed download url>', self::MAX_DOWNLOAD_BYTES);
    }

    /**
     * Uploads a body to a pre-signed absolute URL (for example a batch part) without any credentials,
     * using exactly the method and headers KSeF prescribed.
     *
     * @param array<string, string> $headers
     */
    public function upload(string $absoluteUrl, string $method, array $headers, string $body): ApiResponse
    {
        if (!str_starts_with($absoluteUrl, 'https://')) {
            throw new ConfigurationException('Refusing to upload to a non-https URL.');
        }
        if (!\in_array(strtoupper($method), ['PUT', 'POST'], true)) {
            throw new ConfigurationException('Unsupported upload method.');
        }

        $request = $this->requestFactory->createRequest(strtoupper($method), $absoluteUrl)
            ->withHeader('User-Agent', $this->userAgent)
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            // Pre-signed uploads need the headers KSeF names (for example the blob type), nothing that redirects or authenticates.
            if (!\in_array(strtolower($name), ['authorization', 'host', 'cookie', 'proxy-authorization', 'content-length', 'transfer-encoding'], true)) {
                $request = $request->withHeader($name, $value);
            }
        }

        // Re-uploading the same part to the same URL is idempotent, so network retries are safe.
        return $this->execute($request, RetryMode::Safe, strtoupper($method) . ' <pre-signed upload url>');
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
        // Every request body in the KSeF API is a JSON object, so an empty array means "{}".
        if ($body === []) {
            return '{}';
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new SerializationException('The request payload cannot be encoded as JSON.', [], $e);
        }
    }

    private function execute(RequestInterface $request, RetryMode $mode, string $label, int $maxBody = self::MAX_API_BODY_BYTES): ApiResponse
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

                if (str_contains($label, 'pre-signed')) {
                    // The client's message (and its previous chain) embeds the signed URL, which works as a bearer credential.
                    throw new TransportException(\sprintf('HTTP transport failure for %s (%s).', $label, $e::class));
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
                return $this->toApiResponse($response, $label, $maxBody);
            }

            $retryAfter = $this->retryAfter($response);
            $error = $this->errorParser->parse($status, $this->readBody($response, self::MAX_ERROR_BODY_BYTES, false, $label), $retryAfter);

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

    /**
     * Reads at most $limit bytes. A larger body is refused ($strict) or cut off, so a misbehaving or hostile
     * server cannot exhaust memory.
     */
    private function readBody(ResponseInterface $response, int $limit, bool $strict, string $label): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $size = $body->getSize();
        if ($strict && $size !== null && $size > $limit) {
            throw new TransportException(\sprintf('The response for %s is larger than the allowed %d bytes.', $label, $limit));
        }

        $data = '';
        while (!$body->eof() && \strlen($data) <= $limit) {
            $chunk = $body->read(min(1024 * 1024, $limit + 1 - \strlen($data)));
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (\strlen($data) > $limit) {
            if ($strict) {
                throw new TransportException(\sprintf('The response for %s is larger than the allowed %d bytes.', $label, $limit));
            }
            $data = substr($data, 0, $limit);
        }

        return $data;
    }

    private function toApiResponse(ResponseInterface $response, string $label, int $maxBody): ApiResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new ApiResponse($response->getStatusCode(), $headers, $this->readBody($response, $maxBody, true, $label));
    }
}
