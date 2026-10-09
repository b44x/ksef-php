<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\InvoiceNotAvailableException;
use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Export\ExportStatus;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Status\DownloadedInvoice;
use B4x\Ksef\Support\KsefNumber;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Typed wrapper over the invoice retrieval and search endpoints. */
final class InvoiceApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    public function download(KsefNumber $ksefNumber): DownloadedInvoice
    {
        try {
            $response = $this->client->send(ApiRequest::get('/invoices/ksef/' . rawurlencode($ksefNumber->value), null, [], 'application/xml'));
        } catch (ApiException $e) {
            if ($e->httpStatus === 406) {
                throw new InvoiceNotAvailableException(
                    \sprintf('Invoice %s is not available for download yet (HTTP 406). It is usually still being stored; retry shortly.', $ksefNumber->value),
                    406,
                    $e->errors,
                    $e->traceId,
                    $e,
                );
            }

            throw $e;
        }
        $hash = $response->header('x-ms-meta-hash');
        if ($hash === null) {
            throw new MalformedResponseException('KSeF did not send the invoice hash header (x-ms-meta-hash).');
        }

        $invoice = new DownloadedInvoice($ksefNumber->value, $response->body, $hash);
        if (!$invoice->verifyHash()) {
            throw new MalformedResponseException('The downloaded invoice does not match the hash announced by KSeF.');
        }

        return $invoice;
    }

    public function queryMetadata(
        InvoiceSubjectType $subject,
        InvoiceDateType $dateType,
        DateTimeInterface $from,
        ?DateTimeInterface $to = null,
        int $pageOffset = 0,
        int $pageSize = 100,
    ): InvoiceMetadataPage {
        $range = ['dateType' => $dateType->value, 'from' => $this->utc($from)];
        if ($to !== null) {
            $range['to'] = $this->utc($to);
        }

        $response = $this->client->send(ApiRequest::post(
            '/invoices/query/metadata',
            ['subjectType' => $subject->value, 'dateRange' => $range],
            null,
            RetryMode::Safe,
            ['pageOffset' => $pageOffset, 'pageSize' => $pageSize, 'sortOrder' => 'Asc'],
        ));
        $data = new Payload($response->json());

        return new InvoiceMetadataPage(
            array_map(InvoiceMetadata::fromPayload(...), $data->objects('invoices')),
            $data->bool('hasMore'),
            $data->bool('isTruncated'),
        );
    }

    /**
     * @param array{encryptedSymmetricKey: string, initializationVector: string, publicKeyId: string} $encryption
     *
     * @return string the export reference number
     */
    public function startExport(InvoiceSubjectType $subject, InvoiceDateType $dateType, DateTimeInterface $from, ?DateTimeInterface $to, array $encryption): string
    {
        $range = ['dateType' => $dateType->value, 'from' => $this->utc($from)];
        if ($to !== null) {
            $range['to'] = $this->utc($to);
        }

        $response = $this->client->send(ApiRequest::post(
            '/invoices/exports',
            ['encryption' => $encryption, 'filters' => ['subjectType' => $subject->value, 'dateRange' => $range]],
            null,
            RetryMode::RateLimitOnly,
        ));

        return (new Payload($response->json()))->string('referenceNumber');
    }

    public function exportStatus(string $reference): ExportStatus
    {
        return ExportStatus::fromPayload(new Payload($this->client->send(ApiRequest::get('/invoices/exports/' . rawurlencode($reference)))->json()));
    }

    private function utc(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
