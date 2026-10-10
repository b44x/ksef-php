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
use B4x\Ksef\Status\OpenedBatch;
use Psr\Log\LoggerInterface;
use Throwable;

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

            // The ciphertext hash and size of every part must be declared when the session opens. Only those two values
            // are kept: with the same key and IV a part encrypts to the same bytes again, so each part is encrypted a
            // second time just before its upload. Memory stays at one part (at most 100 MB) instead of the whole archive.
            $declared = [];
            for ($ordinal = 1; $ordinal <= $package->partCount; ++$ordinal) {
                $cipher = $encryption->encrypt($package->part($ordinal));
                $declared[] = ['ordinalNumber' => $ordinal, 'fileSize' => \strlen($cipher), 'fileHash' => Digest::sha256Base64($cipher)];
                unset($cipher);
            }

            $opened = $this->api->openBatch($formCode, $encryption->encryptionInfo($this->keys), [
                'fileSize' => $package->zipSize,
                'fileHash' => $package->zipHash,
                'fileParts' => $declared,
            ], $offline);
            $this->logger->info('KSeF batch session opened.', ['session' => $opened->referenceNumber, 'parts' => $package->partCount]);

            try {
                $this->upload($opened, $package, $encryption, $declared);
            } catch (Throwable $e) {
                $this->abandon($opened->referenceNumber);

                throw $e;
            }

            $this->api->closeBatch($opened->referenceNumber);
            $this->logger->info('KSeF batch session closed.', ['session' => $opened->referenceNumber]);

            return new BatchSubmission($opened->referenceNumber, $package->invoiceHashes);
        } finally {
            $package->dispose();
        }
    }

    /**
     * Closing a session whose parts are incomplete makes KSeF reject it right away (the archive hash cannot match),
     * instead of leaving it open until it expires. Best effort: the original failure is what matters.
     */
    private function abandon(string $reference): void
    {
        try {
            $this->api->closeBatch($reference);
            $this->logger->warning('KSeF batch session closed after a failed upload.', ['session' => $reference]);
        } catch (Throwable) {
            $this->logger->warning('KSeF batch session could not be closed after a failed upload; it will expire.', ['session' => $reference]);
        }
    }

    /**
     * @param list<array{ordinalNumber: int, fileSize: int, fileHash: string}> $declared
     */
    private function upload(OpenedBatch $opened, BatchPackage $package, SessionEncryption $encryption, array $declared): void
    {
        if (\count($opened->uploads) !== $package->partCount) {
            throw new SessionException(\sprintf('KSeF announced %d upload slots for %d parts.', \count($opened->uploads), $package->partCount));
        }
        foreach ($opened->uploads as $upload) {
            if ($upload->ordinalNumber < 1 || $upload->ordinalNumber > $package->partCount) {
                throw new SessionException(\sprintf('KSeF asked for an undeclared batch part %d.', $upload->ordinalNumber));
            }
            $cipher = $encryption->encrypt($package->part($upload->ordinalNumber));
            if (!hash_equals($declared[$upload->ordinalNumber - 1]['fileHash'], Digest::sha256Base64($cipher))) {
                throw new SessionException(\sprintf('Batch part %d no longer encrypts to the declared bytes.', $upload->ordinalNumber));
            }
            $this->transport->upload($upload->url, $upload->method, $upload->headers, $cipher);
            unset($cipher);
        }
    }
}
