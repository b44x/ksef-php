<?php

declare(strict_types=1);

namespace B4x\Ksef\Peppol;

use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Pagination\Page;
use B4x\Ksef\Support\Constraint;

/** Typed wrapper over `/peppol/query`.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class PeppolApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @return Page<PeppolProvider>
     */
    public function providers(int $pageOffset = 0, int $pageSize = 10): Page
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $data = new Payload($this->client->send(ApiRequest::get('/peppol/query', null, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return new Page(array_map(static fn(Payload $p): PeppolProvider => new PeppolProvider($p->string('id'), $p->string('name'), $p->date('dateCreated')), $data->objects('peppolProviders')), $data->bool('hasMore'));
    }
}
