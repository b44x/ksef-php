<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Exception\PermissionOperationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Permissions\AuthorizationDirection;
use B4x\Ksef\Permissions\EntityAuthorizationType;
use B4x\Ksef\Permissions\EntityPermissionType;
use B4x\Ksef\Permissions\EuEntityPermissionType;
use B4x\Ksef\Permissions\EuEntitySubject;
use B4x\Ksef\Permissions\IndirectTarget;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Permissions\PersonSubject;
use B4x\Ksef\Permissions\SubunitContext;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\FakeKsef;

final class PermissionsFlowTest extends KsefTestCase
{
    public function testGrantingToAPersonSendsTheDocumentedBodyAndWaitsForTheOperation(): void
    {
        $this->ksef->json('POST', '/permissions/persons/grants', 202, ['referenceNumber' => 'op-1']);
        $this->ksef->json('GET', '/permissions/operations/op-1', 200, ['status' => ['code' => 100, 'description' => 'accepted']]);
        $this->ksef->json('GET', '/permissions/operations/op-1', 200, ['status' => ['code' => 200, 'description' => 'done']]);

        $this->client()->grantPersonPermissions(PersonSubject::byPesel('90010112345', 'Anna', 'Nowak'), [Permission::InvoiceRead, Permission::InvoiceWrite], 'accountant');

        self::assertSame([
            'subjectIdentifier' => ['type' => 'Pesel', 'value' => '90010112345'],
            'subjectDetails' => ['subjectDetailsType' => 'PersonByIdentifier', 'personById' => ['firstName' => 'Anna', 'lastName' => 'Nowak']],
            'permissions' => ['InvoiceRead', 'InvoiceWrite'],
            'description' => 'accountant',
        ], FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/persons/grants')[0]));
        self::assertCount(2, $this->ksef->requestsTo('GET', '/permissions/operations/op-1'));
    }

    public function testFailedOperationsRaiseWithKsefsStatus(): void
    {
        $this->ksef->json('POST', '/permissions/entities/grants', 202, ['referenceNumber' => 'op-2']);
        $this->ksef->json('GET', '/permissions/operations/op-2', 200, ['status' => ['code' => 420, 'description' => 'No right to do this']]);

        try {
            $this->client()->grantEntityPermissions(Nip::of('5265877635'), 'Partner sp. z o.o.', ['InvoiceRead' => true], 'partner');
            self::fail('Expected PermissionOperationException');
        } catch (PermissionOperationException $e) {
            self::assertSame(420, $e->status->code);
        }
        self::assertSame(
            ['subjectIdentifier' => ['type' => 'Nip', 'value' => '5265877635'], 'permissions' => [['type' => 'InvoiceRead', 'canDelegate' => true]], 'description' => 'partner', 'subjectDetails' => ['fullName' => 'Partner sp. z o.o.']],
            FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/entities/grants')[0]),
        );
    }

    public function testListingAndRevoking(): void
    {
        $this->ksef->json('POST', '/permissions/query/persons/grants', 200, ['hasMore' => false, 'permissions' => [[
            'id' => 'perm-1', 'authorizedIdentifier' => ['type' => 'Pesel', 'value' => '90010112345'], 'authorIdentifier' => ['type' => 'Nip', 'value' => '5265877635'],
            'permissionScope' => 'InvoiceRead', 'description' => 'accountant', 'permissionState' => 'Active', 'startDate' => '2026-06-01T10:00:00+00:00', 'canDelegate' => false,
        ]]]);
        $this->ksef->json('DELETE', '/permissions/common/grants/perm-1', 202, ['referenceNumber' => 'op-3']);
        $this->ksef->json('GET', '/permissions/operations/op-3', 200, ['status' => ['code' => 200, 'description' => 'done']]);
        $client = $this->client();

        $page = $client->personPermissions(true);
        $client->revokePermission($page['permissions'][0]->id);

        self::assertSame('Pesel', $page['permissions'][0]->holderType);
        self::assertTrue($page['permissions'][0]->isActive());
        self::assertSame(['queryType' => 'PermissionsGrantedInCurrentContext', 'permissionState' => 'Active'], FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/query/persons/grants')[0]));
        self::assertCount(1, $this->ksef->requestsTo('DELETE', '/permissions/common/grants/perm-1'));
    }

    private function done(string $reference): void
    {
        $this->ksef->json('GET', '/permissions/operations/' . $reference, 200, ['status' => ['code' => 200, 'description' => 'done']]);
    }

    public function testAuthorizationsAreGrantedQueriedAndRevokedThroughTheirOwnEndpoints(): void
    {
        $this->ksef->json('POST', '/permissions/authorizations/grants', 202, ['referenceNumber' => 'a-1']);
        $this->done('a-1');
        $this->ksef->json('POST', '/permissions/authorizations/grants', 202, ['referenceNumber' => 'a-2']);
        $this->done('a-2');
        $this->ksef->json('POST', '/permissions/query/authorizations/grants', 200, ['hasMore' => false, 'authorizationGrants' => [[
            'id' => 'auth-1', 'authorizationScope' => 'SelfInvoicing', 'description' => 'self billing', 'startDate' => '2026-06-01T10:00:00+00:00',
            'authorizingEntityIdentifier' => ['type' => 'Nip', 'value' => '5265877635'], 'authorizedEntityIdentifier' => ['type' => 'PeppolId', 'value' => 'P-1'],
        ]]]);
        $this->ksef->json('DELETE', '/permissions/authorizations/grants/auth-1', 202, ['referenceNumber' => 'a-3']);
        $this->done('a-3');
        $client = $this->client();

        $client->grantAuthorization(Nip::of('5265877635'), EntityAuthorizationType::SelfInvoicing, 'Partner sp. z o.o.', 'self billing');
        $client->grantAuthorization('PL-PEPPOL-1', EntityAuthorizationType::PefInvoicing, 'Peppol partner', 'peppol');
        $page = $client->authorizations(AuthorizationDirection::Granted);
        $client->revokeAuthorization($page['permissions'][0]->id);

        $bodies = $this->ksef->requestsTo('POST', '/permissions/authorizations/grants');
        self::assertSame(['subjectIdentifier' => ['type' => 'Nip', 'value' => '5265877635'], 'permission' => 'SelfInvoicing', 'description' => 'self billing', 'subjectDetails' => ['fullName' => 'Partner sp. z o.o.']], FakeKsef::body($bodies[0]));
        self::assertSame(['type' => 'PeppolId', 'value' => 'PL-PEPPOL-1'], FakeKsef::body($bodies[1])['subjectIdentifier']);
        self::assertSame(['queryType' => 'Granted'], FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/query/authorizations/grants')[0]));
        self::assertSame('PeppolId', $page['permissions'][0]->authorized->type);
        self::assertNull($page['permissions'][0]->author);
        self::assertCount(1, $this->ksef->requestsTo('DELETE', '/permissions/authorizations/grants/auth-1'));
    }

    public function testIndirectAndSubunitGrantsCarryTheirContext(): void
    {
        $this->ksef->json('POST', '/permissions/indirect/grants', 202, ['referenceNumber' => 'i-1']);
        $this->done('i-1');
        $this->ksef->json('POST', '/permissions/indirect/grants', 202, ['referenceNumber' => 'i-2']);
        $this->done('i-2');
        $this->ksef->json('POST', '/permissions/subunits/grants', 202, ['referenceNumber' => 's-1']);
        $this->done('s-1');
        $client = $this->client();
        $person = PersonSubject::byPesel('90010112345', 'Anna', 'Nowak');

        $client->grantIndirectPermissions($person, [EntityPermissionType::InvoiceRead], 'accounting', IndirectTarget::nip(Nip::of('5265877635')));
        $client->grantIndirectPermissions($person, [EntityPermissionType::InvoiceWrite], 'all customers', IndirectTarget::allPartners());
        $client->grantSubunitAdministrator($person, SubunitContext::internalId('5265877635-12345'), 'branch admin', 'Branch');

        $indirect = $this->ksef->requestsTo('POST', '/permissions/indirect/grants');
        self::assertSame(['type' => 'Nip', 'value' => '5265877635'], FakeKsef::body($indirect[0])['targetIdentifier']);
        self::assertSame(['type' => 'AllPartners'], FakeKsef::body($indirect[1])['targetIdentifier']);
        self::assertSame(['InvoiceRead'], FakeKsef::body($indirect[0])['permissions']);
        $subunit = FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/subunits/grants')[0]);
        self::assertSame(['type' => 'InternalId', 'value' => '5265877635-12345'], $subunit['contextIdentifier']);
        self::assertSame('Branch', $subunit['subunitName']);
    }

    public function testEuEntityGrantsUseFingerprintsOnly(): void
    {
        $this->ksef->json('POST', '/permissions/eu-entities/administration/grants', 202, ['referenceNumber' => 'e-1']);
        $this->done('e-1');
        $this->ksef->json('POST', '/permissions/eu-entities/grants', 202, ['referenceNumber' => 'e-2']);
        $this->done('e-2');
        $client = $this->client();
        $fingerprint = str_repeat('ab', 32);

        $client->grantEuEntityAdministrator(EuEntitySubject::person(PersonSubject::byFingerprint($fingerprint, 'Hans', 'Muster', '90010112345')), '5265877635-DE123456789', 'Muster GmbH', 'Berlin', 'eu admin');
        $client->grantEuEntityRepresentative(EuEntitySubject::entity($fingerprint, 'Seal GmbH', 'Berlin'), [EuEntityPermissionType::InvoiceWrite], 'eu rep');

        $admin = FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/eu-entities/administration/grants')[0]);
        self::assertSame(['type' => 'NipVatUe', 'value' => '5265877635-DE123456789'], $admin['contextIdentifier']);
        self::assertSame(['fullName' => 'Muster GmbH', 'address' => 'Berlin'], $admin['euEntityDetails']);
        self::assertSame(['type' => 'Fingerprint', 'value' => strtoupper($fingerprint)], $admin['subjectIdentifier']);
        $rep = FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/eu-entities/grants')[0]);
        self::assertSame(['subjectDetailsType' => 'EntityByFingerprint', 'entityByFp' => ['fullName' => 'Seal GmbH', 'address' => 'Berlin']], $rep['subjectDetails']);
        self::assertSame(['InvoiceWrite'], $rep['permissions']);

        $this->expectException(ValidationException::class);
        EuEntitySubject::person(PersonSubject::byPesel('90010112345', 'A', 'B'));
    }

    public function testRolesSubordinatesSubunitsEuEntitiesAndAttachmentStatusAreParsed(): void
    {
        $this->ksef->json('GET', '/permissions/query/entities/roles', 200, ['hasMore' => false, 'roles' => [['role' => 'VatGroupUnit', 'description' => 'VAT group', 'startDate' => '2026-01-01T00:00:00+00:00', 'parentEntityIdentifier' => ['type' => 'Nip', 'value' => '5265877635']]]]);
        $this->ksef->json('POST', '/permissions/query/subordinate-entities/roles', 200, ['hasMore' => true, 'roles' => [['role' => 'VatGroupSubUnit', 'description' => 'member', 'startDate' => '2026-01-01T00:00:00+00:00', 'subordinateEntityIdentifier' => ['type' => 'Nip', 'value' => '1111111111']]]]);
        $this->ksef->json('POST', '/permissions/query/subunits/grants', 200, ['hasMore' => false, 'permissions' => [[
            'id' => 'su-1', 'permissionScope' => 'CredentialsManage', 'description' => 'branch admin', 'startDate' => '2026-06-01T10:00:00+00:00', 'subunitName' => 'Branch',
            'authorizedIdentifier' => ['type' => 'Pesel', 'value' => '90010112345'], 'subunitIdentifier' => ['type' => 'InternalId', 'value' => '5265877635-12345'],
        ]]]);
        $this->ksef->json('POST', '/permissions/query/eu-entities/grants', 200, ['hasMore' => false, 'permissions' => [[
            'id' => 'eu-1', 'permissionScope' => 'VatUeManage', 'description' => 'eu admin', 'startDate' => '2026-06-01T10:00:00+00:00', 'vatUeIdentifier' => 'DE123456789',
            'euEntityName' => 'Muster GmbH', 'authorizedFingerprintIdentifier' => 'AB',
        ]]]);
        $this->ksef->json('GET', '/permissions/attachments/status', 200, ['isAttachmentAllowed' => true, 'revokedDate' => null]);
        $client = $this->client();

        $roles = $client->entityRoles();
        $subordinates = $client->subordinateEntities(Nip::of('1111111111'));
        $subunits = $client->subunitAdministrators(SubunitContext::internalId('5265877635-12345'));
        $eu = $client->euEntityPermissions();
        $attachments = $client->attachmentStatus();

        self::assertSame('5265877635', $roles['roles'][0]->entity?->value);
        self::assertTrue($subordinates['hasMore']);
        self::assertSame('1111111111', $subordinates['roles'][0]->entity?->value);
        self::assertSame('Branch', $subunits['permissions'][0]->subunitName);
        self::assertSame(['subunitIdentifier' => ['type' => 'InternalId', 'value' => '5265877635-12345']], FakeKsef::body($this->ksef->requestsTo('POST', '/permissions/query/subunits/grants')[0]));
        self::assertSame('DE123456789', $eu['permissions'][0]->vatUeIdentifier);
        self::assertTrue($attachments->allowed);
        self::assertNull($attachments->revokedAt);
    }

    public function testIdentifiersAreValidatedLocally(): void
    {
        $this->expectException(ValidationException::class);
        PersonSubject::byPesel('123', 'A', 'B');
    }
}
