<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Testing;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Testing\TestData;
use B4x\Ksef\Testing\TestIdentifierType;
use B4x\Ksef\Testing\TestSubjectType;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TestDataTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->route(static fn() => Http::json(200, []));
    }

    public function testEachOperationCallsItsEndpointWithTheDocumentedBody(): void
    {
        $data = $this->data();
        $nip = Nip::unchecked('7762811692');

        $data->createSubject($nip, TestSubjectType::LocalGovernmentUnit, 'Urząd Miasta', ['1234563218' => 'Wydział Finansów'], new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
        $data->removeSubject($nip);
        $data->removePerson($nip);
        $data->grantPermissions($nip, TestIdentifierType::Pesel, '15062788702', [Permission::InvoiceRead, Permission::InvoiceWrite], 'testing access');
        $data->revokePermissions($nip, TestIdentifierType::Fingerprint, str_repeat('a', 64));
        $data->allowAttachments($nip);
        $data->revokeAttachments($nip, new DateTimeImmutable('2026-12-31'));
        $data->blockContext(ContextIdentifier::nip('7762811692'));
        $data->unblockContext(ContextIdentifier::nip('7762811692'));

        $calls = array_map(fn($r): string => $r->getMethod() . ' ' . $r->getUri()->getPath() . ' ' . (string) $r->getBody(), $this->http->requests);
        self::assertSame([
            'POST /v2/testdata/subject {"subjectNip":"7762811692","subjectType":"JST","description":"Urząd Miasta","subunits":[{"subjectNip":"1234563218","description":"Wydział Finansów"}],"createdDate":"2026-01-02T03:04:05+00:00"}',
            'POST /v2/testdata/subject/remove {"subjectNip":"7762811692"}',
            'POST /v2/testdata/person/remove {"nip":"7762811692"}',
            'POST /v2/testdata/permissions {"contextIdentifier":{"type":"Nip","value":"7762811692"},"authorizedIdentifier":{"type":"Pesel","value":"15062788702"},"permissions":[{"permissionType":"InvoiceRead","description":"testing access"},{"permissionType":"InvoiceWrite","description":"testing access"}]}',
            'POST /v2/testdata/permissions/revoke {"contextIdentifier":{"type":"Nip","value":"7762811692"},"authorizedIdentifier":{"type":"Fingerprint","value":"' . str_repeat('a', 64) . '"}}',
            'POST /v2/testdata/attachment {"nip":"7762811692"}',
            'POST /v2/testdata/attachment/revoke {"nip":"7762811692","expectedEndDate":"2026-12-31"}',
            'POST /v2/testdata/context/block {"contextIdentifier":{"type":"Nip","value":"7762811692"}}',
            'POST /v2/testdata/context/unblock {"contextIdentifier":{"type":"Nip","value":"7762811692"}}',
        ], $calls);
        self::assertFalse($this->http->requests[0]->hasHeader('Authorization'), 'These operations need no login.');
    }

    public function testDescriptionsAreCheckedLocally(): void
    {
        $this->expectException(ValidationException::class);
        $this->data()->grantPermissions(Nip::unchecked('7762811692'), TestIdentifierType::Nip, '1234563218', [Permission::InvoiceRead], 'abc');
    }

    public function testItRefusesEnvironmentsOtherThanTest(): void
    {
        $factory = Http::factory();

        $this->expectException(ConfigurationException::class);
        new TestData($this->http, $factory, $factory, Environment::Production);
    }

    private function data(): TestData
    {
        $factory = Http::factory();

        return new TestData($this->http, $factory, $factory);
    }
}
