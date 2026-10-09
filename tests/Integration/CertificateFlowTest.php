<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\TestPki;

final class CertificateFlowTest extends KsefTestCase
{
    public function testRequestingACertificateRunsTheWholeEnrollmentFlow(): void
    {
        $issued = TestPki::selfSigned();
        $this->ksef->json('GET', '/certificates/limits', 200, ['canRequest' => true, 'enrollment' => ['limit' => 300, 'remaining' => 299], 'certificate' => ['limit' => 100, 'remaining' => 99]]);
        $this->ksef->json('GET', '/certificates/enrollments/data', 200, ['commonName' => 'Test Seal', 'countryName' => 'PL', 'organizationName' => 'Test sp. z o.o.', 'organizationIdentifier' => 'VATPL-5265877635']);
        $this->ksef->json('POST', '/certificates/enrollments', 202, ['referenceNumber' => 'enr-1', 'timestamp' => '2026-06-01T10:00:00+00:00']);
        $this->ksef->json('GET', '/certificates/enrollments/enr-1', 200, ['requestDate' => '2026-06-01T10:00:00+00:00', 'status' => ['code' => 100, 'description' => 'In progress']]);
        $this->ksef->json('GET', '/certificates/enrollments/enr-1', 200, ['requestDate' => '2026-06-01T10:00:00+00:00', 'status' => ['code' => 200, 'description' => 'Done'], 'certificateSerialNumber' => '0123ABCD']);
        $this->ksef->json('POST', '/certificates/retrieve', 200, ['certificates' => [[
            'certificate' => base64_encode($issued['certificateDer']),
            'certificateName' => 'my cert',
            'certificateSerialNumber' => '0123ABCD',
            'certificateType' => 'Authentication',
        ]]]);

        $certificate = $this->client()->requestCertificate('my cert', CertificateType::Authentication);

        self::assertSame('0123ABCD', $certificate->serialNumber);
        self::assertSame(CertificateType::Authentication, $certificate->type);
        self::assertStringContainsString('BEGIN CERTIFICATE', $certificate->certificatePem);
        self::assertStringContainsString('PRIVATE KEY', $certificate->privateKeyPem);

        $enroll = FakeKsef::body($this->ksef->requestsTo('POST', '/certificates/enrollments')[0]);
        self::assertSame(['certificateName', 'certificateType', 'csr'], array_keys($enroll));
        self::assertSame('Authentication', $enroll['certificateType']);
        self::assertSame(['0123ABCD'], FakeKsef::body($this->ksef->requestsTo('POST', '/certificates/retrieve')[0])['certificateSerialNumbers']);
    }

    public function testTheLimitIsCheckedBeforeAnythingIsGenerated(): void
    {
        $this->ksef->json('GET', '/certificates/limits', 200, ['canRequest' => false, 'enrollment' => ['limit' => 12, 'remaining' => 0], 'certificate' => ['limit' => 6, 'remaining' => 0]]);

        try {
            $this->client()->requestCertificate('x', CertificateType::Offline);
            self::fail('Expected SessionException');
        } catch (SessionException $e) {
            self::assertStringContainsString('limit', $e->getMessage());
        }
        self::assertSame([], $this->ksef->requestsTo('POST', '/certificates/enrollments'));
    }

    public function testRejectedRequestsAreReportedWithKsefsReason(): void
    {
        $this->ksef->json('GET', '/certificates/limits', 200, ['canRequest' => true, 'enrollment' => ['limit' => 1, 'remaining' => 1], 'certificate' => ['limit' => 1, 'remaining' => 1]]);
        $this->ksef->json('GET', '/certificates/enrollments/data', 200, ['commonName' => 'X', 'countryName' => 'PL']);
        $this->ksef->json('POST', '/certificates/enrollments', 202, ['referenceNumber' => 'enr-1', 'timestamp' => '2026-06-01T10:00:00+00:00']);
        $this->ksef->json('GET', '/certificates/enrollments/enr-1', 200, ['requestDate' => '2026-06-01T10:00:00+00:00', 'status' => ['code' => 400, 'description' => 'Rejected', 'details' => ['The public key was already certified by another subject.']]]);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('already certified');
        $this->client()->requestCertificate('x', CertificateType::Authentication);
    }

    public function testSearchSendsAnObjectEvenWithoutFilters(): void
    {
        $this->ksef->on('POST', '/certificates/query', fn() => Http::json(200, ['hasMore' => false, 'certificates' => [[
            'certificateSerialNumber' => '01AB',
            'name' => 'n',
            'type' => 'Offline',
            'commonName' => 'cn',
            'status' => 'Active',
            'subjectIdentifier' => ['type' => 'Nip', 'value' => '5265877635'],
            'validFrom' => '2026-01-01T00:00:00+00:00',
            'validTo' => '2028-01-01T00:00:00+00:00',
            'requestDate' => '2026-01-01T00:00:00+00:00',
        ]]]));

        $result = $this->client()->searchCertificates();

        self::assertSame('{}', (string) $this->ksef->requestsTo('POST', '/certificates/query')[0]->getBody());
        self::assertTrue($result['certificates'][0]->isActive());
        self::assertSame(CertificateType::Offline, $result['certificates'][0]->type);
    }

    public function testCertificatesOnlyServeTheirOwnPurpose(): void
    {
        $pki = TestPki::selfSigned();
        $offline = new \B4x\Ksef\Certificates\IssuedCertificate('01', 'n', CertificateType::Offline, $pki['certificatePem'], $pki['privateKeyPem']);

        self::assertSame('01', strtoupper($offline->toOfflineCertificate()->serialNumber()) === '' ? '' : '01');
        $this->expectException(SigningException::class);
        $offline->toCredentials();
    }
}
