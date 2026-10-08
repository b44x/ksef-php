<?php

declare(strict_types=1);

namespace Ksef\Session;

use DateTimeImmutable;
use Ksef\Api\SessionApi;
use Ksef\Crypto\Digest;
use Ksef\Crypto\SessionEncryption;
use Ksef\Exception\ApiException;
use Ksef\Exception\SessionException;
use Ksef\Exception\SubmissionOutcomeUnknownException;
use Ksef\Exception\TransportException;
use Ksef\Exception\ValidationException;
use Ksef\Invoice\FormCode;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceDocument;
use Ksef\Polling\Poller;
use Ksef\Polling\PollingPolicy;
use Ksef\Status\InvoiceSubmission;
use Ksef\Status\SessionInvoice;
use Ksef\Status\SessionStatus;
use Ksef\Status\Upo;
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
 * document for asynchronous processing*. It never retries after a network failure, because the
 * document may have been received. In that case a {@see SubmissionOutcomeUnknownException} is
 * thrown; use {@see self::findSubmission()} or send the same document again (KSeF answers
 * duplicates with status 440 instead of storing them twice).
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
    ) {}

    /**
     * @param Invoice|InvoiceDocument|string $invoice a typed invoice, a verified document, or raw FA(3) XML
     *
     * @throws ValidationException when the invoice is invalid (nothing was sent)
     * @throws ApiException when KSeF refused the request (nothing was stored)
     * @throws SubmissionOutcomeUnknownException when the outcome cannot be determined
     * @throws SessionException when the session is closed or expired
     */
    public function send(Invoice|InvoiceDocument|string $invoice): InvoiceSubmission
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
        ];

        try {
            $invoiceReference = $this->api->sendInvoice($this->referenceNumber, $payload);
        } catch (TransportException $e) {
            throw $this->unknownOutcome($document->hash(), $e);
        } catch (ApiException $e) {
            if ($e->httpStatus >= 500) {
                throw $this->unknownOutcome($document->hash(), $e);
            }

            throw $e;
        }

        $this->logger->info('Invoice accepted by KSeF for processing.', ['session' => $this->referenceNumber, 'invoice_reference' => $invoiceReference]);

        return new InvoiceSubmission($this->referenceNumber, $invoiceReference, $document->hash());
    }

    /** Current processing state of a submitted invoice (one request, no waiting). */
    public function invoice(InvoiceSubmission|string $submission): SessionInvoice
    {
        return $this->api->invoice($this->referenceNumber, \is_string($submission) ? $submission : $submission->invoiceReference);
    }

    /**
     * Polls until KSeF has finished processing the invoice. The result may be a rejection:
     * check `$result->status->isAccepted()` or call `SessionInvoice::assertAccepted()`.
     *
     * @throws \Ksef\Exception\PollingTimeoutException
     */
    public function waitForInvoice(InvoiceSubmission|string $submission, ?PollingPolicy $policy = null): SessionInvoice
    {
        $reference = \is_string($submission) ? $submission : $submission->invoiceReference;

        return $this->poller->poll(
            fn(): SessionInvoice => $this->api->invoice($this->referenceNumber, $reference),
            static fn(SessionInvoice $invoice): bool => $invoice->status->isTerminal(),
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
     * @throws \Ksef\Exception\PollingTimeoutException
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
