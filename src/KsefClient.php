<?php

declare(strict_types=1);

namespace Ksef;

use DateTimeInterface;
use Ksef\Api\InvoiceApi;
use Ksef\Api\InvoiceDateType;
use Ksef\Api\InvoiceMetadataPage;
use Ksef\Api\InvoiceSubjectType;
use Ksef\Api\SessionApi;
use Ksef\Crypto\PublicKeyProvider;
use Ksef\Crypto\SessionEncryption;
use Ksef\Exception\KsefException;
use Ksef\Invoice\FormCode;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceDocument;
use Ksef\Polling\Poller;
use Ksef\Polling\PollingPolicy;
use Ksef\Session\InvoiceFactory;
use Ksef\Session\OnlineSession;
use Ksef\Status\DownloadedInvoice;
use Ksef\Status\InvoiceSubmission;
use Ksef\Status\SessionInvoice;
use Ksef\Status\SessionStatus;
use Ksef\Status\Upo;
use Ksef\Support\KsefNumber;
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
        private readonly PublicKeyProvider $keys,
        private readonly InvoiceFactory $factory,
        private readonly Poller $poller,
        private readonly PollingPolicy $polling,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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

        return new OnlineSession($opened->referenceNumber, $opened->validUntil, $formCode, $encryption, $this->sessions, $this->factory, $this->poller, $this->polling, $this->clock, $this->logger);
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

    /** Current state of a submitted invoice (a single request). */
    public function invoiceStatus(InvoiceSubmission $submission): SessionInvoice
    {
        return $this->sessions->invoice($submission->sessionReference, $submission->invoiceReference);
    }

    /**
     * Polls until KSeF finished processing the invoice (accepted or rejected).
     *
     * @throws Exception\PollingTimeoutException when it takes longer than the policy allows
     */
    public function waitForInvoice(InvoiceSubmission $submission, ?PollingPolicy $policy = null): SessionInvoice
    {
        return $this->poller->poll(
            fn(): SessionInvoice => $this->invoiceStatus($submission),
            static fn(SessionInvoice $invoice): bool => $invoice->status->isTerminal(),
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
     * @throws Exception\ValidationException when the KSeF number is malformed
     */
    public function downloadInvoice(string $ksefNumber): DownloadedInvoice
    {
        return $this->invoices->download(KsefNumber::of($ksefNumber));
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
