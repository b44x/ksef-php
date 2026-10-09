<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Exception\PermissionOperationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Permissions\PersonSubject;
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

    public function testIdentifiersAreValidatedLocally(): void
    {
        $this->expectException(ValidationException::class);
        PersonSubject::byPesel('123', 'A', 'B');
    }
}
