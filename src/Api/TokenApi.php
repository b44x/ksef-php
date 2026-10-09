<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;

/** Typed wrapper over the `/tokens` endpoints (KSeF token management). */
final class TokenApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @param non-empty-list<TokenPermission> $permissions
     */
    public function generate(array $permissions, string $description): GeneratedToken
    {
        $body = [
            'permissions' => array_map(static fn(TokenPermission $p): string => $p->value, $permissions),
            'description' => $description,
        ];
        $data = new Payload($this->client->send(ApiRequest::post('/tokens', $body, null, RetryMode::RateLimitOnly))->json());

        return new GeneratedToken($data->string('referenceNumber'), $data->string('token'));
    }

    public function status(string $referenceNumber): TokenStatus
    {
        $data = new Payload($this->client->send(ApiRequest::get('/tokens/' . rawurlencode($referenceNumber)))->json());
        $status = TokenStatus::tryFrom($data->string('status'));

        return $status ?? throw new MalformedResponseException('KSeF reported an unknown token status.');
    }

    public function revoke(string $referenceNumber): void
    {
        $this->client->send(ApiRequest::delete('/tokens/' . rawurlencode($referenceNumber)));
    }
}
