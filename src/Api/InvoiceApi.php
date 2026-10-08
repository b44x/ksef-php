<?php

declare(strict_types=1);

namespace Ksef\Api;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ksef\Exception\ApiException;
use Ksef\Exception\InvoiceNotAvailableException;
use Ksef\Exception\MalformedResponseException;
use Ksef\Http\ApiRequest;
use Ksef\Http\AuthorizedClient;
use Ksef\Http\Payload;
use Ksef\Http\RetryMode;
use Ksef\Status\DownloadedInvoice;
use Ksef\Support\KsefNumber;

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

    private function utc(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
