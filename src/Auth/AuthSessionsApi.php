<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Support\Constraint;

/** Typed wrapper over `/auth/sessions` (listing and revoking logins).
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class AuthSessionsApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @return array{sessions: list<AuthSession>, continuationToken: string|null}
     */
    public function list(?string $continuationToken = null, int $pageSize = 20): array
    {
        Constraint::pageSize($pageSize, 10, 100);
        $request = ApiRequest::get('/auth/sessions', null, ['pageSize' => $pageSize]);
        if ($continuationToken !== null) {
            $request = $request->withHeaders(['x-continuation-token' => $continuationToken]);
        }
        $data = new Payload($this->client->send($request)->json());

        return [
            'sessions' => array_map(AuthSession::fromPayload(...), $data->objects('items')),
            'continuationToken' => $data->optionalString('continuationToken'),
        ];
    }

    /** Revokes the session of the access token in use; its refresh token stops working. */
    public function revokeCurrent(): void
    {
        $this->client->send(ApiRequest::delete('/auth/sessions/current'));
    }

    public function revoke(string $referenceNumber): void
    {
        $this->client->send(ApiRequest::delete('/auth/sessions/' . rawurlencode($referenceNumber)));
    }
}
