<?php

declare(strict_types=1);

namespace Ksef\Http;

/**
 * A request to the KSeF API, described independently of any HTTP library.
 *
 * Instances are internal plumbing of the endpoint classes; consumers do not build them.
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
        public ?string $bearerToken = null,
        public RetryMode $retry = RetryMode::Safe,
        public string $contentType = 'application/json',
        public string $accept = 'application/json',
    ) {}

    /**
     * @param array<string, scalar|null> $query
     */
    public static function get(string $path, ?string $bearerToken = null, array $query = [], string $accept = 'application/json'): self
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
        ?string $bearerToken = null,
        RetryMode $retry = RetryMode::RateLimitOnly,
        array $query = [],
        string $contentType = 'application/json',
    ): self {
        return new self('POST', $path, $query, $body, $bearerToken, $retry, $contentType);
    }

    public static function delete(string $path, ?string $bearerToken = null): self
    {
        return new self('DELETE', $path, [], null, $bearerToken, RetryMode::Never);
    }
}
