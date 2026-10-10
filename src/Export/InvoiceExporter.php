<?php

declare(strict_types=1);

namespace B4x\Ksef\Export;

use B4x\Ksef\Api\InvoiceApi;
use B4x\Ksef\Api\InvoiceDateType;
use B4x\Ksef\Api\InvoiceSubjectType;
use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use DateTimeInterface;
use Throwable;

/**
 * Runs an invoice export end to end: start, wait, download the encrypted parts, verify and decrypt them
 * into one ZIP file. Memory use is bounded by one part (<= 50 MB).
 *
 * @internal use KsefClient::exportInvoices()
 */
final class InvoiceExporter
{
    public function __construct(
        private readonly InvoiceApi $api,
        private readonly Transport $transport,
        private readonly PublicKeyProvider $keys,
        private readonly Poller $poller,
    ) {}

    public function export(
        InvoiceSubjectType $subject,
        InvoiceDateType $dateType,
        DateTimeInterface $from,
        ?DateTimeInterface $to,
        string $destinationZip,
        PollingPolicy $policy,
    ): ExportedPackage {
        $encryption = SessionEncryption::generate();
        $reference = $this->api->startExport($subject, $dateType, $from, $to, $encryption->encryptionInfo($this->keys));

        $status = $this->poller->poll(
            fn(): ExportStatus => $this->api->exportStatus($reference),
            static fn(ExportStatus $status): bool => !$status->isInProgress(),
            $policy,
            \sprintf('export %s to be prepared', $reference),
        );
        if (!$status->isReady()) {
            throw new SessionException(\sprintf('The invoice export did not succeed (%d): %s %s', $status->code, $status->description, implode('; ', $status->details)));
        }

        $parts = $status->parts;
        usort($parts, static fn($a, $b): int => $a->ordinalNumber <=> $b->ordinalNumber);
        foreach ($parts as $index => $part) {
            if ($part->ordinalNumber !== $index + 1) {
                throw new MalformedResponseException(\sprintf('The export parts are not numbered 1..%d without gaps (found %d at position %d).', \count($parts), $part->ordinalNumber, $index + 1));
            }
        }

        $previousUmask = umask(0o077); // exported invoices are business data: owner-only by default
        try {
            $handle = fopen($destinationZip, 'wb');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new ConfigurationException(\sprintf('Cannot write to "%s".', $destinationZip));
        }

        try {
            foreach ($parts as $part) {
                $cipher = $this->transport->download($part->url, 'application/octet-stream')->body;
                if (!hash_equals($part->encryptedHash, Digest::sha256Base64($cipher))) {
                    throw new MalformedResponseException(\sprintf('Export part %d is corrupt (encrypted hash mismatch).', $part->ordinalNumber));
                }
                $plain = $encryption->decrypt($cipher);
                if (!hash_equals($part->hash, Digest::sha256Base64($plain))) {
                    throw new MalformedResponseException(\sprintf('Export part %d is corrupt (hash mismatch after decryption).', $part->ordinalNumber));
                }
                fwrite($handle, $plain);
            }
        } catch (Throwable $e) {
            fclose($handle);
            if (is_file($destinationZip)) {
                unlink($destinationZip);
            }

            throw $e;
        }
        fclose($handle);

        return new ExportedPackage($destinationZip, $status->invoiceCount ?? 0, $status->isTruncated, $status->lastPermanentStorageDate, $status->permanentStorageHwmDate);
    }
}
