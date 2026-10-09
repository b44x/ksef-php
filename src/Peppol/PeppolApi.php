<?php

declare(strict_types=1);

namespace B4x\Ksef\Peppol;

use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;

/** Typed wrapper over `/peppol/query`. */
final class PeppolApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @return array{providers: list<PeppolProvider>, hasMore: bool}
     */
    public function providers(int $pageOffset = 0, int $pageSize = 10): array
    {
        $data = new Payload($this->client->send(ApiRequest::get('/peppol/query', null, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return [
            'providers' => array_map(static fn(Payload $p): PeppolProvider => new PeppolProvider($p->string('id'), $p->string('name'), $p->date('dateCreated')), $data->objects('peppolProviders')),
            'hasMore' => $data->bool('hasMore'),
        ];
    }
}
