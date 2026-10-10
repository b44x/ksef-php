<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use SensitiveParameter;

/**
 * A request to the KSeF API, described independently of any HTTP library.
 *
 * Instances are internal plumbing of the endpoint classes; consumers do not build them.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final readonly class ApiRequest
{
    /**
     * @param array<string, scalar|null> $query
     * @param array<array-key, mixed>|string|null $body arrays are JSON encoded, strings are sent as is
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array|string|null $body = null,
        #[SensitiveParameter]
        public ?string $bearerToken = null,
        public RetryMode $retry = RetryMode::Safe,
        public string $contentType = 'application/json',
        public string $accept = 'application/json',
        /** @var array<string, string> */
        public array $headers = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'path' => $this->path, 'query' => $this->query, 'bearerToken' => $this->bearerToken === null ? null : '***', 'retry' => $this->retry];
    }

    public function withBearerToken(#[SensitiveParameter] string $token): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $token, $this->retry, $this->contentType, $this->accept, $this->headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->bearerToken, $this->retry, $this->contentType, $this->accept, $headers + $this->headers);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public static function get(string $path, #[SensitiveParameter] ?string $bearerToken = null, array $query = [], string $accept = 'application/json'): self
    {
        return new self('GET', $path, $query, null, $bearerToken, RetryMode::Safe, accept: $accept);
    }

    /**
     * @param array<array-key, mixed>|string|null $body
     * @param array<string, scalar|null> $query
     */
    public static function post(
        string $path,
        array|string|null $body = null,
        #[SensitiveParameter]
        ?string $bearerToken = null,
        RetryMode $retry = RetryMode::RateLimitOnly,
        array $query = [],
        string $contentType = 'application/json',
    ): self {
        return new self('POST', $path, $query, $body, $bearerToken, $retry, $contentType);
    }

    public static function delete(string $path, #[SensitiveParameter] ?string $bearerToken = null): self
    {
        return new self('DELETE', $path, [], null, $bearerToken, RetryMode::Never);
    }
}
