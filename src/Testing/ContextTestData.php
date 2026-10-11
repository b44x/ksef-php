<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Limits\LimitsApi;
use B4x\Ksef\Limits\RateLimit;
use B4x\Ksef\Limits\SessionLimits;
use DateTimeInterface;

/**
 * The TEST-only operations that act on the context you are logged in as: shrink or restore its limits to see how your
 * integration copes with them. Get it from `KsefClient::testData()`, which exists on the TEST environment only.
 */
final class ContextTestData
{
    /** The rate limit groups the TEST API accepts for an override (the effective limits list a few more). */
    private const RATE_LIMIT_GROUPS = ['onlineSession', 'batchSession', 'invoiceSend', 'invoiceStatus', 'sessionList', 'sessionInvoiceList', 'sessionMisc', 'invoiceMetadata', 'invoiceExport', 'invoiceExportStatus', 'invoiceDownload', 'collectiveIdentifier', 'other'];

    /** @internal use KsefClient::testData() */
    public function __construct(
        private readonly AuthorizedClient $client,
        private readonly LimitsApi $limits,
    ) {}

    /**
     * Changes the session limits of this context.
     *
     * @param int $maxCollectiveInvoices invoices per collective identifier (2 to 5,000)
     *
     * @throws ApiException|ValidationException
     */
    public function setSessionLimits(SessionLimits $online, SessionLimits $batch, int $maxCollectiveInvoices = 5000): void
    {
        if ($maxCollectiveInvoices < 2 || $maxCollectiveInvoices > 5000) {
            throw new ValidationException('A collective identifier takes 2 to 5,000 invoices.');
        }
        $this->send(ApiRequest::post('/testdata/limits/context/session', [
            'onlineSession' => $this->sessionBody($online),
            'batchSession' => $this->sessionBody($batch),
            'collectiveIdentifier' => ['maxInvoices' => $maxCollectiveInvoices],
        ], null, RetryMode::Never));
    }

    /** @throws ApiException */
    public function resetSessionLimits(): void
    {
        $this->send(ApiRequest::delete('/testdata/limits/context/session'));
    }

    /**
     * Changes how many certificate enrolments and certificates the entity may have (null leaves a limit unchanged).
     *
     * @throws ApiException
     */
    public function setCertificateLimits(?int $maxEnrollments, ?int $maxCertificates, TestIdentifierType $subjectType = TestIdentifierType::Nip): void
    {
        $this->send(ApiRequest::post('/testdata/limits/subject/certificate', [
            'subjectIdentifierType' => $subjectType->value,
            'enrollment' => ['maxEnrollments' => $maxEnrollments],
            'certificate' => ['maxCertificates' => $maxCertificates],
        ], null, RetryMode::Never));
    }

    /** @throws ApiException */
    public function resetCertificateLimits(): void
    {
        $this->send(ApiRequest::delete('/testdata/limits/subject/certificate'));
    }

    /**
     * Changes the API rate limits of this context. Give only the groups you want to change (keys as in
     * `KsefClient::rateLimits()`: `invoiceSend`, `invoiceStatus`, `sessionList`, ...); the others keep their current values.
     *
     * @param array<string, RateLimit> $overrides
     *
     * @throws ApiException|ValidationException
     */
    public function setRateLimits(array $overrides): void
    {
        foreach (array_keys($overrides) as $group) {
            if (!\in_array($group, self::RATE_LIMIT_GROUPS, true)) {
                throw new ValidationException(\sprintf('Unknown rate limit group "%s"; known: %s.', $group, implode(', ', self::RATE_LIMIT_GROUPS)));
            }
        }

        $merged = array_merge($this->limits->rates(), $overrides);
        $body = [];
        foreach (self::RATE_LIMIT_GROUPS as $group) {
            $limit = $merged[$group] ?? throw new ValidationException(\sprintf('The current limits do not include "%s".', $group));
            $body[$group] = ['perSecond' => $limit->perSecond, 'perMinute' => $limit->perMinute, 'perHour' => $limit->perHour];
        }
        $this->send(ApiRequest::post('/testdata/rate-limits', ['rateLimits' => $body], null, RetryMode::Never));
    }

    /** @throws ApiException */
    public function resetRateLimits(): void
    {
        $this->send(ApiRequest::delete('/testdata/rate-limits'));
    }

    /** Switches this context to the rate limits that apply in production (the TEST defaults are far more generous). @throws ApiException */
    public function useProductionRateLimits(): void
    {
        $this->send(ApiRequest::post('/testdata/rate-limits/production', null, null, RetryMode::Never));
    }

    /**
     * Changes the end of validity of a certificate: for testing what your integration does when one expires.
     *
     * @throws ApiException
     */
    public function setCertificateValidity(string $serialNumber, DateTimeInterface $validTo): void
    {
        $this->send(ApiRequest::put('/testdata/certificates/' . rawurlencode($serialNumber), ['validTo' => $validTo->format(DATE_ATOM)]));
    }

    /**
     * @return array<string, int>
     */
    private function sessionBody(SessionLimits $limits): array
    {
        return [
            'maxInvoices' => $limits->maxInvoices,
            'maxInvoiceSizeInMB' => $limits->maxInvoiceSizeMb,
            'maxInvoiceWithAttachmentSizeInMB' => $limits->maxInvoiceWithAttachmentSizeMb,
        ];
    }

    private function send(ApiRequest $request): void
    {
        $this->client->send($request);
    }
}
