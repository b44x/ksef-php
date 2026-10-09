<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Support\Nip;

/** Typed wrapper over the commonly used `/permissions/*` endpoints. */
final class PermissionsApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @param non-empty-list<Permission> $permissions
     *
     * @return string the operation reference number
     */
    public function grantToPerson(PersonSubject $subject, array $permissions, string $description): string
    {
        $body = $subject->toArray() + [
            'permissions' => array_map(static fn(Permission $p): string => $p->value, $permissions),
            'description' => $description,
        ];

        return $this->operation(ApiRequest::post('/permissions/persons/grants', $body, null, RetryMode::RateLimitOnly));
    }

    /**
     * @param non-empty-array<string, bool> $permissions permission type value => may delegate
     *
     * @return string the operation reference number
     */
    public function grantToEntity(Nip $nip, string $fullName, array $permissions, string $description): string
    {
        $list = [];
        foreach ($permissions as $type => $canDelegate) {
            $list[] = ['type' => $type, 'canDelegate' => $canDelegate];
        }

        return $this->operation(ApiRequest::post('/permissions/entities/grants', [
            'subjectIdentifier' => ['type' => 'Nip', 'value' => $nip->value],
            'permissions' => $list,
            'description' => $description,
            'subjectDetails' => ['fullName' => $fullName],
        ], null, RetryMode::RateLimitOnly));
    }

    /** @return string the operation reference number */
    public function revoke(string $permissionId): string
    {
        return $this->operation(ApiRequest::delete('/permissions/common/grants/' . rawurlencode($permissionId)));
    }

    public function operationStatus(string $reference): OperationStatus
    {
        $status = (new Payload($this->client->send(ApiRequest::get('/permissions/operations/' . rawurlencode($reference)))->json()))->object('status');

        return new OperationStatus($status->int('code'), $status->string('description'), $status->strings('details'));
    }

    /**
     * Permissions the authenticated subject holds.
     *
     * @return array{permissions: list<PermissionGrant>, hasMore: bool}
     */
    public function personal(bool $activeOnly = true, int $pageOffset = 0, int $pageSize = 10): array
    {
        return $this->query('/permissions/query/personal/grants', $activeOnly ? ['permissionState' => 'Active'] : [], $pageOffset, $pageSize);
    }

    /**
     * Permissions that persons hold in the current context (`$grantedByMe` limits to those this subject granted).
     *
     * @return array{permissions: list<PermissionGrant>, hasMore: bool}
     */
    public function persons(bool $grantedByMe = false, bool $activeOnly = true, int $pageOffset = 0, int $pageSize = 10): array
    {
        $body = ['queryType' => $grantedByMe ? 'PermissionsGrantedInCurrentContext' : 'PermissionsInCurrentContext'];
        if ($activeOnly) {
            $body['permissionState'] = 'Active';
        }

        return $this->query('/permissions/query/persons/grants', $body, $pageOffset, $pageSize);
    }

    /**
     * Invoice-handling permissions other entities granted to the current context.
     *
     * @return array{permissions: list<PermissionGrant>, hasMore: bool}
     */
    public function entities(int $pageOffset = 0, int $pageSize = 10): array
    {
        return $this->query('/permissions/query/entities/grants', [], $pageOffset, $pageSize);
    }

    private function operation(ApiRequest $request): string
    {
        return (new Payload($this->client->send($request)->json()))->string('referenceNumber');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{permissions: list<PermissionGrant>, hasMore: bool}
     */
    private function query(string $path, array $body, int $pageOffset, int $pageSize): array
    {
        $data = new Payload($this->client->send(ApiRequest::post($path, $body, null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['permissions' => array_map(PermissionGrant::fromPayload(...), $data->objects('permissions')), 'hasMore' => $data->bool('hasMore')];
    }
}
