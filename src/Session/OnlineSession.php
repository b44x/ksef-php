<?php

declare(strict_types=1);

namespace B4x\Ksef\Session;

use B4x\Ksef\Api\SessionApi;
use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Exception\SubmissionOutcomeUnknownException;
use B4x\Ksef\Exception\TransportException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\NativeSleeper;
use B4x\Ksef\Http\Sleeper;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Status\InvoiceSubmission;
use B4x\Ksef\Status\SessionInvoice;
use B4x\Ksef\Status\SessionStatus;
use B4x\Ksef\Status\Upo;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An open interactive session: encrypts and submits invoices, and exposes their status.
 *
 * Obtain instances from `KsefClient::openOnlineSession()`. A session lives at most 12 hours,
 * can hold up to 10,000 invoices and must be closed to trigger the aggregate UPO.
 *
 * Submission semantics (important): {@see self::send()} only tells that KSeF *accepted the
 * document for asynchronous processing*. When the network fails or KSeF answers 5xx mid-send, the
 * document may or may not have arrived. The session then reconciles on its own (see
 * {@see SubmissionRecoveryPolicy}): it searches the session for the document's hash and, if absent,
 * re-sends the identical document, which KSeF's duplicate detection (status 440) makes safe. Only if
 * that stays inconclusive a {@see SubmissionOutcomeUnknownException} is thrown.
 */
final class OnlineSession
{
    private bool $closed = false;

    /**
     * @internal use KsefClient::openOnlineSession()
     */
    public function __construct(
        public readonly string $referenceNumber,
        public readonly DateTimeImmutable $validUntil,
        private readonly FormCode $formCode,
        private readonly SessionEncryption $encryption,
        private readonly SessionApi $api,
        private readonly InvoiceFactory $invoices,
        private readonly Poller $poller,
        private readonly PollingPolicy $polling,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly SubmissionRecoveryPolicy $recovery = new SubmissionRecoveryPolicy(),
        private readonly Sleeper $sleeper = new NativeSleeper(),
    ) {}

    /**
     * @param Invoice|InvoiceDocument|string $invoice a typed invoice, a verified document, or raw FA(3) XML
     * @param SendOptions|null $options offline mode and technical correction flags
     *
     * @throws ValidationException when the invoice is invalid (nothing was sent)
     * @throws ApiException when KSeF refused the request (nothing was stored)
     * @throws SubmissionOutcomeUnknownException when the outcome cannot be determined
     * @throws SessionException when the session is closed or expired
     */
    public function send(Invoice|RrInvoice|InvoiceDocument|string $invoice, ?SendOptions $options = null): InvoiceSubmission
    {
        if ($this->closed) {
            throw new SessionException('The session has already been closed.');
        }
        if ($this->clock->now() >= $this->validUntil) {
            throw new SessionException('The session has expired; open a new one.');
        }

        $document = $this->invoices->document($invoice);
        if (!$document->formCode->equals($this->formCode)) {
            throw new SessionException('The invoice schema differs from the schema declared when the session was opened.');
        }

        $encrypted = $this->encryption->encrypt($document->xml);
        $payload = [
            'invoiceHash' => $document->hash(),
            'invoiceSize' => $document->size(),
            'encryptedInvoiceHash' => Digest::sha256Base64($encrypted),
            'encryptedInvoiceSize' => \strlen($encrypted),
            'encryptedInvoiceContent' => base64_encode($encrypted),
        ] + ($options?->toPayload() ?? []);

        $hash = $document->hash();
        $resends = 0;

        while (true) {
            try {
                $invoiceReference = $this->api->sendInvoice($this->referenceNumber, $payload);
                $this->logger->info('Invoice accepted by KSeF for processing.', ['session' => $this->referenceNumber, 'invoice_reference' => $invoiceReference, 'resends' => $resends]);

                return new InvoiceSubmission($this->referenceNumber, $invoiceReference, $hash, $resends > 0);
            } catch (TransportException $e) {
                $failure = $e;
            } catch (ApiException $e) {
                if ($e->httpStatus < 500) {
                    throw $e; // KSeF refused it before processing: definitely not stored.
                }
                $failure = $e;
            }

            $this->logger->warning('Invoice submission outcome unknown; reconciling.', ['session' => $this->referenceNumber, 'error' => $failure::class, 'resends' => $resends]);

            if (!$this->recovery->isEnabled()) {
                throw $this->unknownOutcome($hash, $failure);
            }

            $found = $this->lookUp($hash);
            if ($found !== null) {
                $this->logger->notice('KSeF already has the invoice; no re-send needed.', ['session' => $this->referenceNumber, 'invoice_reference' => $found->invoiceReference]);

                return $found;
            }
            if ($resends >= $this->recovery->maxResends) {
                throw $this->unknownOutcome($hash, $failure);
            }

            ++$resends;
            $this->sleeper->sleep($this->recovery->delayBeforeResend($resends));
        }
    }

    /**
     * Looks the document up in the session. A failing lookup is not conclusive and not fatal:
     * the caller then falls back to re-sending, which KSeF's duplicate detection makes safe.
     */
    private function lookUp(string $hash): ?InvoiceSubmission
    {
        try {
            return $this->findSubmission($hash)?->markRecovered();
        } catch (TransportException | ApiException $e) {
            $this->logger->warning('Could not search the session for the invoice.', ['session' => $this->referenceNumber, 'error' => $e::class]);

            return null;
        }
    }

    /** Current processing state of a submitted invoice (one request, no waiting). */
    public function invoice(InvoiceSubmission|string $submission): SessionInvoice
    {
        return $this->api->invoice($this->referenceNumber, \is_string($submission) ? $submission : $submission->invoiceReference);
    }

    /**
     * Polls until KSeF has finished processing the invoice. The result may be a rejection:
     * check `$result->status->isAccepted()` or call `SessionInvoice::assertAccepted()`.
     * With `$untilStored` an accepted invoice is only returned once it is permanently stored
     * (`permanentStorageDate` set), i.e. when it can be downloaded.
     *
     * @throws \B4x\Ksef\Exception\PollingTimeoutException
     */
    public function waitForInvoice(InvoiceSubmission|string $submission, ?PollingPolicy $policy = null, bool $untilStored = false): SessionInvoice
    {
        $reference = \is_string($submission) ? $submission : $submission->invoiceReference;

        return $this->poller->poll(
            fn(): SessionInvoice => $this->api->invoice($this->referenceNumber, $reference),
            static fn(SessionInvoice $invoice): bool => $invoice->isSettled($untilStored),
            $policy ?? $this->polling,
            \sprintf('invoice %s to be processed', $reference),
        );
    }

    /**
     * Looks through the session's invoices for one with the given content hash.
     * Use it to find out whether an invoice whose submission outcome was unknown reached KSeF.
     */
    public function findSubmission(string $invoiceHash): ?InvoiceSubmission
    {
        $invoice = $this->api->findInvoiceByHash($this->referenceNumber, $invoiceHash);

        return $invoice === null ? null : new InvoiceSubmission($this->referenceNumber, $invoice->referenceNumber, $invoiceHash);
    }

    public function status(): SessionStatus
    {
        return $this->api->status($this->referenceNumber);
    }

    /**
     * Closes the session. KSeF then generates the aggregate UPO asynchronously.
     * Invoices that are still being processed continue to be processed.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->api->close($this->referenceNumber);
        $this->closed = true;
        $this->logger->info('KSeF session closed.', ['session' => $this->referenceNumber]);
    }

    /**
     * Polls until the (closed) session reached a final state and returns it.
     *
     * @throws \B4x\Ksef\Exception\PollingTimeoutException
     */
    public function waitUntilFinished(?PollingPolicy $policy = null): SessionStatus
    {
        return $this->poller->poll(
            fn(): SessionStatus => $this->api->status($this->referenceNumber),
            static fn(SessionStatus $status): bool => $status->isFinished(),
            $policy ?? $this->polling,
            \sprintf('session %s to finish', $this->referenceNumber),
        );
    }

    /** UPO of a single accepted invoice. */
    public function invoiceUpo(InvoiceSubmission|string $submission): Upo
    {
        return $this->api->invoiceUpo($this->referenceNumber, \is_string($submission) ? $submission : $submission->invoiceReference);
    }

    private function unknownOutcome(string $hash, Throwable $cause): SubmissionOutcomeUnknownException
    {
        $this->logger->warning('Invoice submission outcome unknown.', ['session' => $this->referenceNumber, 'error' => $cause::class]);

        return new SubmissionOutcomeUnknownException(
            'The invoice submission failed without a definitive answer from KSeF; the document may or may not have been received. '
            . 'Look it up with findSubmission(), or send the identical document again (duplicates are detected by KSeF). Cause: ' . $cause->getMessage(),
            $this->referenceNumber,
            $hash,
            $cause,
        );
    }
}
