<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** Small helpers to build PSR-7 messages in tests. */
final class Http
{
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function raw(int $status, string $body, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }

    public static function networkError(string $message = 'connection timed out'): NetworkError
    {
        return new NetworkError(new Request('GET', 'https://example.test'), $message);
    }
}
