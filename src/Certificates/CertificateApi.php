<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Support\Constraint;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Typed wrapper over the `/certificates/*` endpoints. Note that requesting certificates requires an
 * access token obtained with a *signature* (XAdES); KSeF token sessions are refused.
 */
final class CertificateApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    public function limits(): CertificateLimits
    {
        $data = new Payload($this->client->send(ApiRequest::get('/certificates/limits'))->json());
        $enrollment = $data->object('enrollment');
        $certificate = $data->object('certificate');

        return new CertificateLimits(
            $data->bool('canRequest'),
            $enrollment->int('limit'),
            $enrollment->int('remaining'),
            $certificate->int('limit'),
            $certificate->int('remaining'),
        );
    }

    public function enrollmentData(): EnrollmentData
    {
        return EnrollmentData::fromPayload(new Payload($this->client->send(ApiRequest::get('/certificates/enrollments/data'))->json()));
    }

    /**
     * @return string the enrollment reference number
     */
    public function enroll(string $name, CertificateType $type, string $csrBase64, ?DateTimeInterface $validFrom = null): string
    {
        Constraint::length('certificate name', $name, 5, 100);
        Constraint::pattern('certificate name', $name, '/^[a-zA-Z0-9_\\- ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]+$/u');
        $body = ['certificateName' => $name, 'certificateType' => $type->value, 'csr' => $csrBase64];
        if ($validFrom !== null) {
            $body['validFrom'] = DateTimeImmutable::createFromInterface($validFrom)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return (new Payload($this->client->send(ApiRequest::post('/certificates/enrollments', $body, null, RetryMode::RateLimitOnly))->json()))->string('referenceNumber');
    }

    public function enrollmentStatus(string $referenceNumber): EnrollmentStatus
    {
        $data = new Payload($this->client->send(ApiRequest::get('/certificates/enrollments/' . rawurlencode($referenceNumber)))->json());
        $status = $data->object('status');

        return new EnrollmentStatus($status->int('code'), $status->string('description'), $status->strings('details'), $data->optionalString('certificateSerialNumber'));
    }

    /**
     * @param non-empty-list<string> $serialNumbers
     *
     * @return array<string, array{certificatePem: string, name: string, type: CertificateType}> keyed by serial number
     */
    public function retrieve(array $serialNumbers): array
    {
        $data = new Payload($this->client->send(ApiRequest::post('/certificates/retrieve', ['certificateSerialNumbers' => $serialNumbers], null, RetryMode::Safe))->json());

        $result = [];
        foreach ($data->objects('certificates') as $item) {
            $der = base64_decode($item->string('certificate'), true);
            $type = CertificateType::tryFrom($item->string('certificateType'));
            if ($der === false || $type === null) {
                throw new MalformedResponseException('KSeF returned an unreadable certificate.');
            }
            $result[$item->string('certificateSerialNumber')] = [
                'certificatePem' => "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n",
                'name' => $item->string('certificateName'),
                'type' => $type,
            ];
        }

        return $result;
    }

    public function revoke(string $serialNumber, RevocationReason $reason = RevocationReason::Unspecified): void
    {
        $this->client->send(ApiRequest::post('/certificates/' . rawurlencode($serialNumber) . '/revoke', ['revocationReason' => $reason->value], null, RetryMode::RateLimitOnly));
    }

    /**
     * @return array{certificates: list<CertificateInfo>, hasMore: bool}
     */
    public function query(?CertificateType $type = null, ?string $status = null, ?string $name = null, ?string $serialNumber = null, int $pageOffset = 0, int $pageSize = 10): array
    {
        Constraint::pageOffset($pageOffset);
        Constraint::pageSize($pageSize, 10, 50);
        if ($serialNumber !== null) {
            Constraint::pattern('certificate serial number', $serialNumber, '/^[0-9A-F]{16}$/');
        }
        $filter = array_filter(['type' => $type?->value, 'status' => $status, 'name' => $name, 'certificateSerialNumber' => $serialNumber], static fn(?string $v): bool => $v !== null);
        $data = new Payload($this->client->send(ApiRequest::post('/certificates/query', $filter, null, RetryMode::Safe, ['pageOffset' => $pageOffset, 'pageSize' => $pageSize]))->json());

        return [
            'certificates' => array_map(CertificateInfo::fromPayload(...), $data->objects('certificates')),
            'hasMore' => $data->bool('hasMore'),
        ];
    }
}
