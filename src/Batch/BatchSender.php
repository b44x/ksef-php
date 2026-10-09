<?php

declare(strict_types=1);

namespace B4x\Ksef\Batch;

use B4x\Ksef\Api\SessionApi;
use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Status\BatchSubmission;
use Psr\Log\LoggerInterface;

/**
 * Runs the batch flow: package -> encrypt parts -> open session -> upload parts -> close.
 *
 * @internal use KsefClient::sendBatch()
 */
final class BatchSender
{
    public function __construct(
        private readonly SessionApi $api,
        private readonly Transport $transport,
        private readonly PublicKeyProvider $keys,
        private readonly BatchPackager $packager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param iterable<InvoiceDocument> $documents
     */
    public function send(iterable $documents, FormCode $formCode, int $maxPartBytes, bool $offline = false): BatchSubmission
    {
        $package = $this->packager->package($documents, $maxPartBytes);

        try {
            $encryption = SessionEncryption::generate();

            // Encrypt every part once; ciphertext hashes and sizes must be declared when opening the session.
            $encrypted = [];
            $declared = [];
            for ($ordinal = 1; $ordinal <= $package->partCount; ++$ordinal) {
                $cipher = $encryption->encrypt($package->part($ordinal));
                $encrypted[$ordinal] = $cipher;
                $declared[] = ['ordinalNumber' => $ordinal, 'fileSize' => \strlen($cipher), 'fileHash' => Digest::sha256Base64($cipher)];
            }

            $opened = $this->api->openBatch($formCode, $encryption->encryptionInfo($this->keys), [
                'fileSize' => $package->zipSize,
                'fileHash' => $package->zipHash,
                'fileParts' => $declared,
            ], $offline);
            $this->logger->info('KSeF batch session opened.', ['session' => $opened->referenceNumber, 'parts' => $package->partCount]);

            if (\count($opened->uploads) !== $package->partCount) {
                throw new SessionException(\sprintf('KSeF announced %d upload slots for %d parts.', \count($opened->uploads), $package->partCount));
            }
            foreach ($opened->uploads as $upload) {
                $cipher = $encrypted[$upload->ordinalNumber] ?? throw new SessionException(\sprintf('KSeF asked for an undeclared batch part %d.', $upload->ordinalNumber));
                $this->transport->upload($upload->url, $upload->method, $upload->headers, $cipher);
            }

            $this->api->closeBatch($opened->referenceNumber);
            $this->logger->info('KSeF batch session closed.', ['session' => $opened->referenceNumber]);

            return new BatchSubmission($opened->referenceNumber, $package->invoiceHashes);
        } finally {
            $package->dispose();
        }
    }
}
