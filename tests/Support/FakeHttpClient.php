<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Deterministic PSR-18 client: replays queued responses (or throws queued exceptions) and records requests.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var (callable(RequestInterface): (ResponseInterface|ClientExceptionInterface|null))|null */
    private $router;

    public function queue(ResponseInterface|ClientExceptionInterface ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /**
     * @param callable(RequestInterface): (ResponseInterface|ClientExceptionInterface|null) $router
     */
    public function route(callable $router): self
    {
        $this->router = $router;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $item = $this->router !== null ? ($this->router)($request) : null;
        $item ??= array_shift($this->queue);

        if ($item === null) {
            throw new LogicException(\sprintf('No response queued for %s %s', $request->getMethod(), $request->getUri()));
        }
        if ($item instanceof ClientExceptionInterface) {
            throw $item;
        }

        return $item;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new LogicException('No request was sent.');
    }
}
