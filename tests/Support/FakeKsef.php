<?php

declare(strict_types=1);

namespace Ksef\Tests\Support;

use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A scriptable in-memory KSeF API. Register handlers per "METHOD /path" and plug {@see self::client()}
 * into the SDK. Unknown routes fail loudly so tests never silently depend on unplanned calls.
 */
final class FakeKsef
{
    /** @var array<string, list<callable(RequestInterface): ResponseInterface>> */
    private array $routes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public readonly FakeHttpClient $http;

    /** Private key matching the public keys served at /security/public-key-certificates. */
    public readonly string $privateKeyPem;

    private readonly string $certificateDer;

    public function __construct(public readonly string $basePath = '/v2')
    {
        $pki = TestPki::selfSigned();
        $this->privateKeyPem = $pki['privateKeyPem'];
        $this->certificateDer = $pki['certificateDer'];
        $this->http = new FakeHttpClient();
        $this->http->route(function (RequestInterface $request): ResponseInterface {
            $this->requests[] = $request;

            return $this->dispatch($request);
        });

        $this->on('GET', '/security/public-key-certificates', fn(): ResponseInterface => Http::json(200, [
            $this->keyEntry('token-key', 'KsefTokenEncryption'),
            $this->keyEntry('symmetric-key', 'SymmetricKeyEncryption'),
        ]));
    }

    /**
     * Registers a handler; several handlers for the same route are consumed in order, the last one repeats.
     *
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    public function on(string $method, string $path, callable $handler): self
    {
        $this->routes[$method . ' ' . $path][] = $handler;

        return $this;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function json(string $method, string $path, int $status, array $data): self
    {
        return $this->on($method, $path, static fn(): ResponseInterface => Http::json($status, $data));
    }

    /** @return list<RequestInterface> */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            fn(RequestInterface $r): bool => $r->getMethod() === $method && $this->path($r) === $path,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $key = $request->getMethod() . ' ' . $this->path($request);
        $handlers = $this->routes[$key] ?? null;
        if ($handlers === null) {
            throw new LogicException('FakeKsef received an unplanned request: ' . $key);
        }

        $handler = \count($handlers) > 1 ? array_shift($handlers) : $handlers[0];
        $this->routes[$key] = \count($this->routes[$key]) > 1 ? $handlers : $this->routes[$key];

        return $handler($request);
    }

    private function path(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return str_starts_with($path, $this->basePath) ? substr($path, \strlen($this->basePath)) : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function keyEntry(string $id, string $usage): array
    {
        return [
            'certificate' => base64_encode($this->certificateDer),
            'certificateId' => 'cert-' . $id,
            'publicKeyId' => $id,
            'validFrom' => '2020-01-01T00:00:00+00:00',
            'validTo' => '2099-01-01T00:00:00+00:00',
            'usage' => [$usage],
        ];
    }
}
