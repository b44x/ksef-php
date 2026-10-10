<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Support\Constraint;
use B4x\Ksef\Support\Nip;

/** Typed wrapper over the commonly used `/permissions/*` endpoints.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
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
        self::description($description);
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
        self::description($description);
        self::name($fullName);
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

    /**
     * Entity-level authorisation (self-invoicing, RR, tax representative, Peppol).
     *
     * @param Nip|string $subject the authorised entity: a NIP, or a Peppol ID given as a string
     *
     * @return string the operation reference number
     */
    public function grantAuthorization(Nip|string $subject, EntityAuthorizationType $type, string $fullName, string $description): string
    {
        self::description($description);
        self::name($fullName);
        $identifier = $subject instanceof Nip ? ['type' => 'Nip', 'value' => $subject->value] : ['type' => 'PeppolId', 'value' => self::nonEmpty($subject, 'Peppol ID')];

        return $this->operation(ApiRequest::post('/permissions/authorizations/grants', [
            'subjectIdentifier' => $identifier,
            'permission' => $type->value,
            'description' => $description,
            'subjectDetails' => ['fullName' => $fullName],
        ], null, RetryMode::RateLimitOnly));
    }

    /** @return string the operation reference number */
    public function revokeAuthorization(string $permissionId): string
    {
        return $this->operation(ApiRequest::delete('/permissions/authorizations/grants/' . rawurlencode($permissionId)));
    }

    /**
     * Permissions a person receives through the current context in other contexts (for example for the
     * customers of an accounting office).
     *
     * @param non-empty-list<EntityPermissionType> $permissions
     *
     * @return string the operation reference number
     */
    public function grantIndirect(PersonSubject $subject, array $permissions, string $description, ?IndirectTarget $target = null): string
    {
        self::description($description);
        $body = $subject->toArray() + [
            'permissions' => array_map(static fn(EntityPermissionType $p): string => $p->value, $permissions),
            'description' => $description,
        ];
        if ($target !== null) {
            $body['targetIdentifier'] = $target->toArray();
        }

        return $this->operation(ApiRequest::post('/permissions/indirect/grants', $body, null, RetryMode::RateLimitOnly));
    }

    /**
     * Makes a person administrator of a subordinate unit or entity.
     *
     * @return string the operation reference number
     */
    public function grantSubunitAdministrator(PersonSubject $subject, SubunitContext $unit, string $description, ?string $subunitName = null): string
    {
        self::description($description);
        $body = $subject->toArray() + ['contextIdentifier' => $unit->toArray(), 'description' => $description];
        if ($subunitName !== null) {
            Constraint::length('subunit name', $subunitName, 5, 256);
            $body['subunitName'] = $subunitName;
        }

        return $this->operation(ApiRequest::post('/permissions/subunits/grants', $body, null, RetryMode::RateLimitOnly));
    }

    /**
     * Makes a certificate holder administrator of an EU entity that is allowed to self-invoice.
     *
     * @param string $vatUe the EU entity's identifier in the context (NIP-VAT UE)
     *
     * @return string the operation reference number
     */
    public function grantEuEntityAdministrator(EuEntitySubject $subject, string $vatUe, string $euEntityName, string $euEntityAddress, string $description): string
    {
        self::description($description);
        Constraint::length('EU entity name', $euEntityName, 5, 256);
        Constraint::length('EU entity name in the details', $euEntityName, 1, 100);
        Constraint::length('EU entity address', $euEntityAddress, 1, 512);

        return $this->operation(ApiRequest::post('/permissions/eu-entities/administration/grants', $subject->toArray() + [
            'contextIdentifier' => ['type' => 'NipVatUe', 'value' => self::nonEmpty($vatUe, 'NIP-VAT UE')],
            'description' => $description,
            'euEntityName' => $euEntityName,
            'euEntityDetails' => ['fullName' => $euEntityName, 'address' => $euEntityAddress],
        ], null, RetryMode::RateLimitOnly));
    }

    /**
     * Gives a representative of an EU entity permission to work in the current (EU-entity) context.
     *
     * @param non-empty-list<EuEntityPermissionType> $permissions
     *
     * @return string the operation reference number
     */
    public function grantEuEntityRepresentative(EuEntitySubject $subject, array $permissions, string $description): string
    {
        self::description($description);
        return $this->operation(ApiRequest::post('/permissions/eu-entities/grants', $subject->toArray() + [
            'permissions' => array_map(static fn(EuEntityPermissionType $p): string => $p->value, $permissions),
            'description' => $description,
        ], null, RetryMode::RateLimitOnly));
    }

    /**
     * Entity-level authorisations granted by or to the current context.
     *
     * @return array{permissions: list<AuthorizationGrant>, hasMore: bool}
     */
    public function authorizations(AuthorizationDirection $direction, int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $data = new Payload($this->client->send(ApiRequest::post('/permissions/query/authorizations/grants', ['queryType' => $direction->value], null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['permissions' => array_map(AuthorizationGrant::fromPayload(...), $data->objects('authorizationGrants')), 'hasMore' => $data->bool('hasMore')];
    }

    /**
     * Administrators of the subordinate units of the current context.
     *
     * @return array{permissions: list<SubunitPermission>, hasMore: bool}
     */
    public function subunitAdministrators(?SubunitContext $unit = null, int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $data = new Payload($this->client->send(ApiRequest::post('/permissions/query/subunits/grants', $unit === null ? [] : ['subunitIdentifier' => $unit->toArray()], null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['permissions' => array_map(SubunitPermission::fromPayload(...), $data->objects('permissions')), 'hasMore' => $data->bool('hasMore')];
    }

    /**
     * Administrators and representatives of EU entities in the current context.
     *
     * @return array{permissions: list<EuEntityPermission>, hasMore: bool}
     */
    public function euEntityPermissions(int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $data = new Payload($this->client->send(ApiRequest::post('/permissions/query/eu-entities/grants', [], null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['permissions' => array_map(EuEntityPermission::fromPayload(...), $data->objects('permissions')), 'hasMore' => $data->bool('hasMore')];
    }

    /**
     * Roles of the current context (court bailiff, local government unit, VAT group unit, ...).
     *
     * @return array{roles: list<EntityRole>, hasMore: bool}
     */
    public function roles(int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $data = new Payload($this->client->send(ApiRequest::get('/permissions/query/entities/roles', null, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['roles' => array_map(EntityRole::ofContext(...), $data->objects('roles')), 'hasMore' => $data->bool('hasMore')];
    }

    /**
     * Subordinate entities of the current context (members of a local government unit or VAT group).
     *
     * @return array{roles: list<EntityRole>, hasMore: bool}
     */
    public function subordinateEntities(?Nip $subordinate = null, int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);
        $body = $subordinate === null ? [] : ['subordinateEntityIdentifier' => ['type' => 'Nip', 'value' => $subordinate->value]];
        $data = new Payload($this->client->send(ApiRequest::post('/permissions/query/subordinate-entities/roles', $body, null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['roles' => array_map(EntityRole::ofSubordinate(...), $data->objects('roles')), 'hasMore' => $data->bool('hasMore')];
    }

    public function attachmentStatus(): AttachmentStatus
    {
        $data = new Payload($this->client->send(ApiRequest::get('/permissions/attachments/status'))->json());

        return new AttachmentStatus($data->optionalBool('isAttachmentAllowed') ?? false, $data->optionalDate('revokedDate'));
    }

    private static function description(string $description): void
    {
        Constraint::length('permission description', $description, 5, 256);
    }

    private static function name(string $fullName): void
    {
        Constraint::length('entity name', $fullName, 5, 90);
    }

    private static function nonEmpty(string $value, string $label): string
    {
        if (trim($value) === '') {
            throw new ValidationException(\sprintf('The %s must not be empty.', $label));
        }

        return $value;
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
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 100);

        $data = new Payload($this->client->send(ApiRequest::post($path, $body, null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return ['permissions' => array_map(PermissionGrant::fromPayload(...), $data->objects('permissions')), 'hasMore' => $data->bool('hasMore')];
    }
}
