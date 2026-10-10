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
use B4x\Ksef\Auth\ContextIdentifier;
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
use B4x\Ksef\Collective\CollectiveIdentifier;
use B4x\Ksef\Collective\CollectiveIdentifierApi;
use B4x\Ksef\Collective\CollectiveIdentifierInvoice;
use B4x\Ksef\Collective\CollectiveIdentifierPage;
use B4x\Ksef\Collective\CollectiveInvoice;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\InvoiceNotAvailableException;
use B4x\Ksef\Exception\KsefException;
use B4x\Ksef\Exception\PermissionOperationException;
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
use B4x\Ksef\Limits\SubjectLimits;
use B4x\Ksef\Offline\OfflineInvoice;
use B4x\Ksef\Offline\OfflineIssuer;
use B4x\Ksef\Pagination\Page;
use B4x\Ksef\Peppol\PeppolApi;
use B4x\Ksef\Peppol\PeppolProvider;
use B4x\Ksef\Permissions\AttachmentStatus;
use B4x\Ksef\Permissions\AuthorizationDirection;
use B4x\Ksef\Permissions\AuthorizationGrant;
use B4x\Ksef\Permissions\EntityAuthorizationType;
use B4x\Ksef\Permissions\EntityPermissionType;
use B4x\Ksef\Permissions\EntityRole;
use B4x\Ksef\Permissions\EuEntityPermission;
use B4x\Ksef\Permissions\EuEntityPermissionType;
use B4x\Ksef\Permissions\EuEntitySubject;
use B4x\Ksef\Permissions\IndirectTarget;
use B4x\Ksef\Permissions\OperationStatus;
use B4x\Ksef\Permissions\Permission;
use B4x\Ksef\Permissions\PermissionGrant;
use B4x\Ksef\Permissions\PermissionsApi;
use B4x\Ksef\Permissions\PersonSubject;
use B4x\Ksef\Permissions\SubunitContext;
use B4x\Ksef\Permissions\SubunitPermission;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Qr\OfflineCertificate;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Session\InvoiceFactory;
use B4x\Ksef\Session\OnlineSession;
use B4x\Ksef\Session\SendOptions;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use B4x\Ksef\Status\BatchSubmission;
use B4x\Ksef\Status\DownloadedInvoice;
use B4x\Ksef\Status\InvoiceSubmission;
use B4x\Ksef\Status\SessionInvoice;
use B4x\Ksef\Status\SessionInvoicesPage;
use B4x\Ksef\Status\SessionStatus;
use B4x\Ksef\Status\Upo;
use B4x\Ksef\Support\KsefNumber;
use B4x\Ksef\Support\Nip;
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
        private readonly ?PermissionsApi $permissions = null,
        private readonly ?Environment $environment = null,
        private readonly ?ContextIdentifier $context = null,
        private readonly ?CollectiveIdentifierApi $collective = null,
        private readonly ?PeppolApi $peppol = null,
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
     * @param Invoice|RrInvoice|InvoiceDocument|string $invoice typed invoice, verified document or raw FA(3) / FA_RR (1) XML
     * @param SendOptions|null $options offline mode / technical correction flags
     *
     * @throws Exception\ValidationException the invoice is invalid; nothing was sent
     * @throws Exception\SubmissionOutcomeUnknownException the network failed mid-submission; see its documentation
     * @throws Exception\ApiException KSeF refused the request
     */
    public function sendInvoice(Invoice|RrInvoice|InvoiceDocument|string $invoice, ?SendOptions $options = null): InvoiceSubmission
    {
        $document = $this->factory->document($invoice);
        $session = $this->openOnlineSession($document->formCode);

        try {
            $submission = $session->send($document, $options);
        } catch (Throwable $failure) {
            $this->closeAfterFailure($session);

            throw $failure;
        }

        $session->close();

        return $submission;
    }

    /**
     * An issuer of offline invoices bound to this client's environment and context. It works without talking
     * to KSeF, so it can be used while KSeF is unavailable.
     *
     * @throws ConfigurationException when the client was configured with a custom base URL instead of an environment
     */
    public function offlineIssuer(OfflineCertificate $certificate): OfflineIssuer
    {
        if ($this->environment === null || $this->context === null) {
            throw new ConfigurationException('Offline invoicing needs a client configured with environment() so that the QR code links can be built.');
        }

        return new OfflineIssuer($this->environment, $this->context, $certificate, $this->clock);
    }

    /** Issues an invoice in offline mode: the XML plus KOD I and KOD II. See docs/OFFLINE.md. */
    public function issueOfflineInvoice(Invoice $invoice, OfflineCertificate $certificate): OfflineInvoice
    {
        return $this->offlineIssuer($certificate)->issue($invoice);
    }

    /**
     * Delivers an offline invoice to KSeF (with `offlineMode: true`). Do this within the deadline of the offline mode.
     *
     * @throws Exception\ApiException|Exception\SubmissionOutcomeUnknownException|Exception\ValidationException
     */
    public function sendOfflineInvoice(OfflineInvoice $invoice): InvoiceSubmission
    {
        return $this->sendInvoice($invoice->document, SendOptions::offline());
    }

    /**
     * Re-sends an offline invoice that KSeF rejected for technical reasons (for example a schema error), linked to
     * the rejected one so that its KOD I keeps working. The business content must stay the same.
     *
     * @param OfflineInvoice|InvoiceDocument|string $rejected the rejected invoice or its Base64 SHA-256 hash
     */
    public function sendTechnicalCorrection(Invoice|InvoiceDocument|string $corrected, OfflineInvoice|InvoiceDocument|string $rejected): InvoiceSubmission
    {
        return $this->sendInvoice($corrected, SendOptions::technicalCorrection($rejected));
    }

    /**
     * Sends many invoices at once as a batch (ZIP, split and encrypted), then closes the session.
     *
     * Everything is verified before upload (XSD, size limits: 10,000 invoices, 50 parts of up to 100 MB).
     * Processing is asynchronous: wait with {@see self::waitForSession()} and read per-invoice results
     * with {@see self::sessionInvoices()}; correlate them via {@see BatchSubmission::$invoiceHashes}.
     * Requires ext-zip.
     *
     * @param iterable<Invoice|RrInvoice|InvoiceDocument|string> $invoices
     */
    public function sendBatch(iterable $invoices, int $maxPartBytes = BatchPackager::DEFAULT_MAX_PART_BYTES, ?FormCode $formCode = null, bool $offline = false): BatchSubmission
    {
        $sender = $this->batches ?? throw new ConfigurationException('Batch support is not configured.');
        $formCode ??= FormCode::fa3();
        $documents = (function () use ($invoices, $formCode): Generator {
            foreach ($invoices as $invoice) {
                $document = $this->factory->document($invoice);
                if (!$document->formCode->equals($formCode)) {
                    throw new Exception\ValidationException(\sprintf('A batch holds one schema only: %s was declared but an invoice uses %s. Pass the matching $formCode.', $formCode->systemCode, $document->formCode->systemCode));
                }
                yield $document;
            }
        })();

        return $sender->send($documents, $formCode, $maxPartBytes, $offline);
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
     * @throws Exception\ApiException when KSeF refuses the request (for example 403 without permission)
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

    /** Limits of the current subject: how many certificate enrolments and certificates it may have. */
    public function subjectLimits(): SubjectLimits
    {
        return ($this->limits ?? throw new ConfigurationException('Limits support is not configured.'))->subject();
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
     * @return Page<AuthSession>
     */
    public function authSessions(?string $continuationToken = null, int $pageSize = 20): Page
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
     * Grants a person permissions to work in the current context and waits until KSeF applied them.
     * Requires the CredentialsManage permission (or owner rights).
     *
     * @param non-empty-list<Permission> $permissions
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantPersonPermissions(PersonSubject $person, array $permissions, string $description, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantToPerson($person, $permissions, $description), $policy);
    }

    /**
     * Grants another entity (company) the right to handle invoices in the current context.
     *
     * @param non-empty-array<string, bool> $permissions {@see EntityPermissionType} value => whether the entity may delegate it
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantEntityPermissions(Nip $entityNip, string $entityName, array $permissions, string $description, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantToEntity($entityNip, $entityName, $permissions, $description), $policy);
    }

    /**
     * Revokes a permission by the id found in {@see PermissionGrant::$id}.
     *
     * @throws PermissionOperationException when KSeF refuses the revocation
     */
    public function revokePermission(string $permissionId, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->revoke($permissionId), $policy);
    }

    /**
     * Permissions the authenticated subject holds.
     *
     * @return Page<PermissionGrant>
     */
    public function myPermissions(bool $activeOnly = true, int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->personal($activeOnly, $pageOffset, $pageSize);
    }

    /**
     * Permissions persons hold in the current context.
     *
     * @return Page<PermissionGrant>
     */
    public function personPermissions(bool $grantedByMe = false, bool $activeOnly = true, int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->persons($grantedByMe, $activeOnly, $pageOffset, $pageSize);
    }

    /**
     * Invoice-handling permissions other entities granted to the current context.
     *
     * @return Page<PermissionGrant>
     */
    public function entityPermissions(int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->entities($pageOffset, $pageSize);
    }

    /**
     * Authorises another entity to act for you in a special way (self-billing, RR invoices, tax
     * representative, Peppol) and waits until KSeF applied it.
     *
     * @param Nip|string $subject the authorised entity: a NIP, or a Peppol ID given as a string
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantAuthorization(Nip|string $subject, EntityAuthorizationType $type, string $fullName, string $description, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantAuthorization($subject, $type, $fullName, $description), $policy);
    }

    /**
     * Revokes an entity-level authorisation by the id found in {@see AuthorizationGrant::$id}
     * (ordinary permissions are revoked with {@see self::revokePermission()}).
     *
     * @throws PermissionOperationException when KSeF refuses the revocation
     */
    public function revokeAuthorization(string $authorizationId, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->revokeAuthorization($authorizationId), $policy);
    }

    /**
     * Gives a person permissions in the contexts of your customers or partners (typical for accounting offices).
     *
     * @param non-empty-list<EntityPermissionType> $permissions
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantIndirectPermissions(PersonSubject $person, array $permissions, string $description, ?IndirectTarget $target = null, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantIndirect($person, $permissions, $description, $target), $policy);
    }

    /**
     * Makes a person administrator of a subordinate unit or entity (local government sub-unit, VAT group member).
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantSubunitAdministrator(PersonSubject $person, SubunitContext $unit, string $description, ?string $subunitName = null, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantSubunitAdministrator($person, $unit, $description, $subunitName), $policy);
    }

    /**
     * Makes a certificate holder administrator of an EU entity that may self-invoice.
     *
     * @param string $vatUe the EU entity's NIP-VAT UE identifier
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantEuEntityAdministrator(EuEntitySubject $administrator, string $vatUe, string $euEntityName, string $euEntityAddress, string $description, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantEuEntityAdministrator($administrator, $vatUe, $euEntityName, $euEntityAddress, $description), $policy);
    }

    /**
     * Gives a representative of an EU entity permissions in the current (EU entity) context.
     *
     * @param non-empty-list<EuEntityPermissionType> $permissions
     *
     * @throws PermissionOperationException when KSeF refuses the grant
     */
    public function grantEuEntityRepresentative(EuEntitySubject $representative, array $permissions, string $description, ?PollingPolicy $policy = null): void
    {
        $api = $this->permissions ?? throw new ConfigurationException('Permission support is not configured.');
        $this->awaitPermissionOperation($api, $api->grantEuEntityRepresentative($representative, $permissions, $description), $policy);
    }

    /**
     * Entity-level authorisations the current context granted or received.
     *
     * @return Page<AuthorizationGrant>
     */
    public function authorizations(AuthorizationDirection $direction, int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->authorizations($direction, $pageOffset, $pageSize);
    }

    /**
     * Administrators of subordinate units, optionally of one unit only.
     *
     * @return Page<SubunitPermission>
     */
    public function subunitAdministrators(?SubunitContext $unit = null, int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->subunitAdministrators($unit, $pageOffset, $pageSize);
    }

    /**
     * @return Page<EuEntityPermission>
     */
    public function euEntityPermissions(int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->euEntityPermissions($pageOffset, $pageSize);
    }

    /**
     * Roles of the current context (court bailiff, local government unit, VAT group unit, ...).
     *
     * @return Page<EntityRole>
     */
    public function entityRoles(int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->roles($pageOffset, $pageSize);
    }

    /**
     * Subordinate entities of the current context.
     *
     * @return Page<EntityRole>
     */
    public function subordinateEntities(?Nip $subordinate = null, int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->subordinateEntities($subordinate, $pageOffset, $pageSize);
    }

    /** Whether the current context may issue invoices with attachments. */
    public function attachmentStatus(): AttachmentStatus
    {
        return ($this->permissions ?? throw new ConfigurationException('Permission support is not configured.'))->attachmentStatus();
    }

    /**
     * Creates a collective identifier: one reference under which a single payment can settle many invoices of
     * the same seller. Needs InvoiceRead, InvoiceWrite or CollectiveIdentifierManage.
     *
     * @param non-empty-list<CollectiveInvoice> $invoices
     *
     * @return string for example "1111111111-IZ202607-65ED02180000-E7"
     */
    public function createCollectiveIdentifier(array $invoices): string
    {
        return $this->collective()->generate($invoices);
    }

    /**
     * Collective identifiers of the context created in a period (at most 100 days).
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifier>
     */
    public function collectiveIdentifiers(DateTimeInterface $from, DateTimeInterface $to, ?string $number = null, ?bool $createdInCurrentContext = null, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        return $this->collective()->query($from, $to, $number, $createdInCurrentContext, $continuationToken, $pageSize);
    }

    /**
     * The invoices that make up collective identifiers (up to 10 at once).
     *
     * @param non-empty-list<string> $numbers
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifierInvoice>
     */
    public function collectiveIdentifierInvoices(array $numbers, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        return $this->collective()->invoices($numbers, $continuationToken, $pageSize);
    }

    /**
     * Collective identifiers an invoice belongs to.
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifier>
     */
    public function collectiveIdentifiersOf(string $ksefNumber, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        return $this->collective()->ofInvoice($ksefNumber, $continuationToken, $pageSize);
    }

    /**
     * Peppol service providers registered in KSeF.
     *
     * @return Page<PeppolProvider>
     */
    public function peppolProviders(int $pageOffset = 0, int $pageSize = 10): Page
    {
        return ($this->peppol ?? throw new ConfigurationException('Peppol support is not configured.'))->providers($pageOffset, $pageSize);
    }

    private function collective(): CollectiveIdentifierApi
    {
        return $this->collective ?? throw new ConfigurationException('Collective identifier support is not configured.');
    }

    private function awaitPermissionOperation(PermissionsApi $api, string $reference, ?PollingPolicy $policy): void
    {
        $status = $this->poller->poll(
            static fn(): OperationStatus => $api->operationStatus($reference),
            static fn(OperationStatus $status): bool => !$status->isInProgress(),
            $policy ?? $this->polling,
            \sprintf('permission operation %s to finish', $reference),
        );
        if (!$status->isSuccessful()) {
            throw new PermissionOperationException($status);
        }
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
     * @return Page<CertificateInfo>
     */
    public function searchCertificates(?CertificateType $type = null, ?string $status = null, ?string $name = null, int $pageOffset = 0, int $pageSize = 10): Page
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
