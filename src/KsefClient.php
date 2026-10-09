<?php

declare(strict_types=1);

namespace B4x\Ksef;

use B4x\Ksef\Api\GeneratedToken;
use B4x\Ksef\Api\InvoiceApi;
use B4x\Ksef\Api\InvoiceDateType;
use B4x\Ksef\Api\InvoiceMetadataPage;
use B4x\Ksef\Api\InvoiceSubjectType;
use B4x\Ksef\Api\SessionApi;
use B4x\Ksef\Api\TokenApi;
use B4x\Ksef\Api\TokenPermission;
use B4x\Ksef\Api\TokenStatus;
use B4x\Ksef\Auth\AuthSession;
use B4x\Ksef\Auth\AuthSessionsApi;
use B4x\Ksef\Batch\BatchPackager;
use B4x\Ksef\Batch\BatchSender;
use B4x\Ksef\Certificates\CertificateApi;
use B4x\Ksef\Certificates\CertificateInfo;
use B4x\Ksef\Certificates\CertificateLimits;
use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Certificates\CsrGenerator;
use B4x\Ksef\Certificates\EnrollmentStatus;
use B4x\Ksef\Certificates\IssuedCertificate;
use B4x\Ksef\Certificates\KeyType;
use B4x\Ksef\Certificates\RevocationReason;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\InvoiceNotAvailableException;
use B4x\Ksef\Exception\KsefException;
use B4x\Ksef\Export\ExportedPackage;
use B4x\Ksef\Export\InvoiceExporter;
use B4x\Ksef\Http\NativeSleeper;
use B4x\Ksef\Http\Sleeper;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Limits\ContextLimits;
use B4x\Ksef\Limits\LimitsApi;
use B4x\Ksef\Limits\RateLimit;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Session\InvoiceFactory;
use B4x\Ksef\Session\OnlineSession;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use B4x\Ksef\Status\BatchSubmission;
use B4x\Ksef\Status\DownloadedInvoice;
use B4x\Ksef\Status\InvoiceSubmission;
use B4x\Ksef\Status\SessionInvoice;
use B4x\Ksef\Status\SessionInvoicesPage;
use B4x\Ksef\Status\SessionStatus;
use B4x\Ksef\Status\Upo;
use B4x\Ksef\Support\KsefNumber;
use DateTimeInterface;
use Generator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entry point of the SDK.
 *
 * ```php
 * $ksef = KsefClient::builder()
 *     ->environment(Environment::Test)
 *     ->httpClient($psr18Client, $psr17Factory, $psr17Factory)
 *     ->context(ContextIdentifier::nip('5265877635'))
 *     ->credentials(new KsefTokenCredentials($token))
 *     ->build();
 *
 * $submission = $ksef->sendInvoice($invoice);          // accepted for processing, NOT yet approved
 * $result = $ksef->waitForInvoice($submission)->assertAccepted();
 * $upo = $ksef->invoiceUpo($submission);
 * ```
 *
 * Authentication, token refresh, encryption, request signing and polling are handled internally.
 */
final class KsefClient
{
    /**
     * @internal use {@see self::builder()}
     */
    public function __construct(
        private readonly SessionApi $sessions,
        private readonly InvoiceApi $invoices,
        private readonly TokenApi $tokenApi,
        private readonly PublicKeyProvider $keys,
        private readonly InvoiceFactory $factory,
        private readonly Poller $poller,
        private readonly PollingPolicy $polling,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly SubmissionRecoveryPolicy $recovery = new SubmissionRecoveryPolicy(),
        private readonly Sleeper $sleeper = new NativeSleeper(),
        private readonly ?BatchSender $batches = null,
        private readonly ?CertificateApi $certificates = null,
        private readonly ?InvoiceExporter $exporter = null,
        private readonly ?LimitsApi $limits = null,
        private readonly ?AuthSessionsApi $authSessions = null,
    ) {}

    public static function builder(): KsefClientBuilder
    {
        return new KsefClientBuilder();
    }

    /**
     * Opens an interactive session for sending several invoices efficiently.
     * Close it with {@see OnlineSession::close()} when done.
     */
    public function openOnlineSession(?FormCode $formCode = null): OnlineSession
    {
        $formCode ??= FormCode::fa3();
        $encryption = SessionEncryption::generate();
        $opened = $this->sessions->open($formCode, $encryption->encryptionInfo($this->keys));

        $this->logger->info('KSeF online session opened.', ['session' => $opened->referenceNumber]);

        return new OnlineSession($opened->referenceNumber, $opened->validUntil, $formCode, $encryption, $this->sessions, $this->factory, $this->poller, $this->polling, $this->clock, $this->logger, $this->recovery, $this->sleeper);
    }

    /**
     * Sends one invoice in its own short-lived session (open, send, close).
     *
     * The returned submission means "accepted for processing". Wait for the verdict with
     * {@see self::waitForInvoice()}. For many invoices open a session yourself and reuse it.
     *
     * @param Invoice|InvoiceDocument|string $invoice typed invoice, verified document or raw FA(3) XML
     *
     * @throws Exception\ValidationException the invoice is invalid; nothing was sent
     * @throws Exception\SubmissionOutcomeUnknownException the network failed mid-submission; see its documentation
     * @throws Exception\ApiException KSeF refused the request
     */
    public function sendInvoice(Invoice|InvoiceDocument|string $invoice): InvoiceSubmission
    {
        $document = $this->factory->document($invoice);
        $session = $this->openOnlineSession($document->formCode);

        try {
            $submission = $session->send($document);
        } catch (Throwable $failure) {
            $this->closeAfterFailure($session);

            throw $failure;
        }

        $session->close();

        return $submission;
    }

    /**
     * Sends many invoices at once as a batch (ZIP, split and encrypted), then closes the session.
     *
     * Everything is verified before upload (XSD, size limits: 10,000 invoices, 50 parts of up to 100 MB).
     * Processing is asynchronous: wait with {@see self::waitForSession()} and read per-invoice results
     * with {@see self::sessionInvoices()}; correlate them via {@see BatchSubmission::$invoiceHashes}.
     * Requires ext-zip.
     *
     * @param iterable<Invoice|InvoiceDocument|string> $invoices
     */
    public function sendBatch(iterable $invoices, int $maxPartBytes = BatchPackager::DEFAULT_MAX_PART_BYTES, ?FormCode $formCode = null): BatchSubmission
    {
        $sender = $this->batches ?? throw new ConfigurationException('Batch support is not configured.');
        $documents = (function () use ($invoices): Generator {
            foreach ($invoices as $invoice) {
                yield $this->factory->document($invoice);
            }
        })();

        return $sender->send($documents, $formCode ?? FormCode::fa3(), $maxPartBytes);
    }

    /**
     * Polls until a session (online or batch) reached a final state.
     *
     * @throws Exception\PollingTimeoutException
     */
    public function waitForSession(string $sessionReference, ?PollingPolicy $policy = null): SessionStatus
    {
        return $this->poller->poll(
            fn(): SessionStatus => $this->sessions->status($sessionReference),
            static fn(SessionStatus $status): bool => $status->isFinished(),
            $policy ?? $this->polling,
            \sprintf('session %s to finish', $sessionReference),
        );
    }

    /** One page of the invoices of a session with their individual results. */
    public function sessionInvoices(string $sessionReference, ?string $continuationToken = null, int $pageSize = 100): SessionInvoicesPage
    {
        return $this->sessions->invoices($sessionReference, $continuationToken, $pageSize);
    }

    /** One page of the invoices KSeF rejected in a session. */
    public function failedSessionInvoices(string $sessionReference, ?string $continuationToken = null, int $pageSize = 100): SessionInvoicesPage
    {
        return $this->sessions->failedInvoices($sessionReference, $continuationToken, $pageSize);
    }

    /** Current state of a submitted invoice (a single request). */
    public function invoiceStatus(InvoiceSubmission $submission): SessionInvoice
    {
        return $this->sessions->invoice($submission->sessionReference, $submission->invoiceReference);
    }

    /**
     * Polls until KSeF finished processing the invoice (accepted or rejected). With `$untilStored`
     * an accepted invoice is returned only once it is permanently stored and therefore downloadable.
     *
     * @throws Exception\PollingTimeoutException when it takes longer than the policy allows
     */
    public function waitForInvoice(InvoiceSubmission $submission, ?PollingPolicy $policy = null, bool $untilStored = false): SessionInvoice
    {
        return $this->poller->poll(
            fn(): SessionInvoice => $this->invoiceStatus($submission),
            static fn(SessionInvoice $invoice): bool => $invoice->isSettled($untilStored),
            $policy ?? $this->polling,
            \sprintf('invoice %s to be processed', $submission->invoiceReference),
        );
    }

    /**
     * Finds out whether a document with the given hash reached KSeF in the given session.
     * Typically used after a {@see Exception\SubmissionOutcomeUnknownException}.
     */
    public function findSubmission(string $sessionReference, string $invoiceHash): ?InvoiceSubmission
    {
        $invoice = $this->sessions->findInvoiceByHash($sessionReference, $invoiceHash);

        return $invoice === null ? null : new InvoiceSubmission($sessionReference, $invoice->referenceNumber, $invoiceHash);
    }

    /** UPO of an accepted invoice. */
    public function invoiceUpo(InvoiceSubmission $submission): Upo
    {
        return $this->sessions->invoiceUpo($submission->sessionReference, $submission->invoiceReference);
    }

    /** State of a session, including the aggregate UPO references after it was closed. */
    public function sessionStatus(string $sessionReference): SessionStatus
    {
        return $this->sessions->status($sessionReference);
    }

    /** One page of the aggregate session UPO (see {@see SessionStatus::$upoPages}). */
    public function sessionUpo(string $sessionReference, string $upoReference): Upo
    {
        return $this->sessions->sessionUpo($sessionReference, $upoReference);
    }

    /**
     * Downloads an invoice stored in KSeF. The content hash announced by KSeF is verified.
     *
     * A just-accepted invoice is not downloadable for a short moment (see {@see InvoiceNotAvailableException}).
     * Pass a polling policy to wait for it instead of handling that exception yourself.
     *
     * @throws Exception\ValidationException when the KSeF number is malformed
     * @throws InvoiceNotAvailableException when the invoice is not stored yet and no wait policy was given
     * @throws Exception\PollingTimeoutException when the wait policy is exhausted
     */
    public function downloadInvoice(string $ksefNumber, ?PollingPolicy $waitWhileUnavailable = null): DownloadedInvoice
    {
        $number = KsefNumber::of($ksefNumber);
        if ($waitWhileUnavailable === null) {
            return $this->invoices->download($number);
        }

        $downloaded = $this->poller->poll(
            function () use ($number): ?DownloadedInvoice {
                try {
                    return $this->invoices->download($number);
                } catch (InvoiceNotAvailableException) {
                    return null;
                }
            },
            static fn(?DownloadedInvoice $invoice): bool => $invoice !== null,
            $waitWhileUnavailable,
            \sprintf('invoice %s to become downloadable', $number->value),
        );

        return $downloaded ?? throw new InvoiceNotAvailableException('The invoice is not available.', 406);
    }

    /**
     * Searches invoice metadata (sales or purchases) within a date range (at most 100 days).
     */
    public function searchInvoices(
        InvoiceSubjectType $subject,
        InvoiceDateType $dateType,
        DateTimeInterface $from,
        ?DateTimeInterface $to = null,
        int $pageOffset = 0,
        int $pageSize = 100,
    ): InvoiceMetadataPage {
        return $this->invoices->queryMetadata($subject, $dateType, $from, $to, $pageOffset, $pageSize);
    }

    /**
     * Exports invoices (sales or purchases) in a date range as one decrypted ZIP file at `$destinationZip`:
     * `{ksefNumber}.xml` for every invoice plus `_metadata.json`. Intended for synchronisation: use
     * {@see InvoiceDateType::PermanentStorage} and continue from {@see ExportedPackage::$continueFrom}.
     * At most 10,000 invoices / 1 GB per export; at most 10 exports may run concurrently.
     *
     * @throws Exception\SessionException when KSeF reports a failure
     */
    public function exportInvoices(
        InvoiceSubjectType $subject,
        InvoiceDateType $dateType,
        DateTimeInterface $from,
        ?DateTimeInterface $to,
        string $destinationZip,
        ?PollingPolicy $policy = null,
    ): ExportedPackage {
        $exporter = $this->exporter ?? throw new ConfigurationException('Export support is not configured.');

        return $exporter->export($subject, $dateType, $from, $to, $destinationZip, $policy ?? $this->polling);
    }

    /** Limits of the current authentication context (sessions, invoice sizes). */
    public function contextLimits(): ContextLimits
    {
        return ($this->limits ?? throw new ConfigurationException('Limits support is not configured.'))->context();
    }

    /**
     * Currently effective request rates per endpoint group.
     *
     * @return array<string, RateLimit>
     */
    public function rateLimits(): array
    {
        return ($this->limits ?? throw new ConfigurationException('Limits support is not configured.'))->rates();
    }

    /**
     * Active logins (authentication sessions) of the subject.
     *
     * @return array{sessions: list<AuthSession>, continuationToken: string|null}
     */
    public function authSessions(?string $continuationToken = null, int $pageSize = 20): array
    {
        return ($this->authSessions ?? throw new ConfigurationException('Authentication session support is not configured.'))->list($continuationToken, $pageSize);
    }

    /** Revokes one login; its refresh token stops working. Without a reference the current login is revoked. */
    public function revokeAuthSession(?string $referenceNumber = null): void
    {
        $api = $this->authSessions ?? throw new ConfigurationException('Authentication session support is not configured.');
        $referenceNumber === null ? $api->revokeCurrent() : $api->revoke($referenceNumber);
    }

    /**
     * Creates a KSeF token for the current context (requires credential-management permission).
     * The secret is returned only once; use it with {@see Auth\KsefTokenCredentials}.
     *
     * @param non-empty-list<TokenPermission> $permissions
     */
    public function generateToken(array $permissions, string $description): GeneratedToken
    {
        return $this->tokenApi->generate($permissions, $description);
    }

    /**
     * Requests a new KSeF certificate: checks the limits, builds a CSR for the subject KSeF dictates, submits it,
     * waits for issuance and fetches the certificate. A new key pair is generated locally (EC P-256 by default);
     * the returned object holds the only copy of the private key.
     *
     * Requires a session authenticated with a *signature* (certificate credentials), not with a KSeF token.
     *
     * @throws Exception\SessionException when KSeF refuses the request or no more certificates may be requested
     */
    public function requestCertificate(string $name, CertificateType $type, KeyType $keyType = KeyType::EcP256, ?PollingPolicy $policy = null): IssuedCertificate
    {
        $api = $this->certificates ?? throw new ConfigurationException('Certificate support is not configured.');

        if (!$api->limits()->canRequest) {
            throw new Exception\SessionException('KSeF reports that no further certificate request is allowed (limit reached).');
        }

        $csr = (new CsrGenerator())->generate($api->enrollmentData(), $keyType);
        $reference = $api->enroll($name, $type, $csr->csrBase64);

        $status = $this->poller->poll(
            static fn(): EnrollmentStatus => $api->enrollmentStatus($reference),
            static fn(EnrollmentStatus $status): bool => !$status->isInProgress(),
            $policy ?? $this->polling,
            \sprintf('certificate request %s to be processed', $reference),
        );
        if (!$status->isIssued() || $status->certificateSerialNumber === null) {
            throw new Exception\SessionException(\sprintf('KSeF did not issue the certificate (%d): %s %s', $status->code, $status->description, implode('; ', $status->details)));
        }

        $retrieved = $api->retrieve([$status->certificateSerialNumber])[$status->certificateSerialNumber] ?? null;
        if ($retrieved === null) {
            throw new Exception\SessionException('KSeF issued the certificate but did not return it.');
        }

        return new IssuedCertificate($status->certificateSerialNumber, $retrieved['name'], $retrieved['type'], $retrieved['certificatePem'], $csr->privateKeyPem);
    }

    public function certificateLimits(): CertificateLimits
    {
        return ($this->certificates ?? throw new ConfigurationException('Certificate support is not configured.'))->limits();
    }

    /**
     * @return array{certificates: list<CertificateInfo>, hasMore: bool}
     */
    public function searchCertificates(?CertificateType $type = null, ?string $status = null, ?string $name = null, int $pageOffset = 0, int $pageSize = 10): array
    {
        return ($this->certificates ?? throw new ConfigurationException('Certificate support is not configured.'))->query($type, $status, $name, null, $pageOffset, $pageSize);
    }

    public function revokeCertificate(string $serialNumber, RevocationReason $reason = RevocationReason::Unspecified): void
    {
        ($this->certificates ?? throw new ConfigurationException('Certificate support is not configured.'))->revoke($serialNumber, $reason);
    }

    /** Waits until a generated token is Active (or reached a failure state). */
    public function waitForToken(string $tokenReference, ?PollingPolicy $policy = null): TokenStatus
    {
        return $this->poller->poll(
            fn(): TokenStatus => $this->tokenApi->status($tokenReference),
            static fn(TokenStatus $status): bool => $status->isFinal(),
            $policy ?? $this->polling,
            \sprintf('token %s to become active', $tokenReference),
        );
    }

    public function revokeToken(string $tokenReference): void
    {
        $this->tokenApi->revoke($tokenReference);
    }

    private function closeAfterFailure(OnlineSession $session): void
    {
        try {
            $session->close();
        } catch (KsefException $closeFailure) {
            // The original failure is more important and is rethrown by the caller. The session
            // expires on its own after 12 hours, so this is only worth a log entry.
            $this->logger->warning('Could not close the KSeF session after a failed submission.', [
                'session' => $session->referenceNumber,
                'error' => $closeFailure::class,
            ]);
        }
    }
}
