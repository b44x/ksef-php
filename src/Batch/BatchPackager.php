<?php

declare(strict_types=1);

namespace B4x\Ksef\Batch;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceDocument;
use Throwable;
use ZipArchive;

/**
 * Builds the batch archive: one ZIP of invoice XML files, split binary into parts of at most
 * {@see self::DEFAULT_MAX_PART_BYTES} (100 MB before encryption, KSeF limit), at most 50 parts.
 */
final class BatchPackager
{
    public const DEFAULT_MAX_PART_BYTES = 100_000_000;
    public const MAX_PARTS = 50;
    public const MAX_INVOICES = 10_000;
    public const MAX_ARCHIVE_BYTES = 5_000_000_000;

    public function __construct(private readonly ?string $temporaryDirectory = null) {}

    /**
     * @param iterable<InvoiceDocument> $documents
     */
    public function package(iterable $documents, int $maxPartBytes = self::DEFAULT_MAX_PART_BYTES): BatchPackage
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ConfigurationException('Batch sessions need the PHP zip extension (ext-zip).');
        }
        if ($maxPartBytes < 1 || $maxPartBytes > self::DEFAULT_MAX_PART_BYTES) {
            throw new ValidationException(\sprintf('The part size must be between 1 and %d bytes.', self::DEFAULT_MAX_PART_BYTES));
        }

        $path = tempnam($this->temporaryDirectory ?? sys_get_temp_dir(), 'ksef-batch-');
        if ($path === false) {
            throw new ConfigurationException('Cannot create a temporary file for the batch archive.');
        }

        try {
            $hashes = $this->writeArchive($path, $documents);
            $size = (int) filesize($path);
            if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
                throw new ValidationException(\sprintf('The batch archive must be between 1 byte and %d bytes, got %d.', self::MAX_ARCHIVE_BYTES, $size));
            }

            $parts = (int) ceil($size / $maxPartBytes);
            if ($parts > self::MAX_PARTS) {
                throw new ValidationException(\sprintf('The archive would need %d parts; KSeF allows at most %d.', $parts, self::MAX_PARTS));
            }
            $partSize = (int) ceil($size / $parts);
            $digest = hash_file('sha256', $path, true);
            if ($digest === false) {
                throw new ConfigurationException('Cannot hash the batch archive.');
            }
            $hash = base64_encode($digest);

            return new BatchPackage($path, $hash, $size, $partSize, (int) ceil($size / $partSize), $hashes);
        } catch (Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $e;
        }
    }

    /**
     * @param iterable<InvoiceDocument> $documents
     *
     * @return list<string>
     */
    private function writeArchive(string $path, iterable $documents): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new ConfigurationException('Cannot create the batch archive.');
        }

        $hashes = [];
        foreach ($documents as $document) {
            if (\count($hashes) >= self::MAX_INVOICES) {
                $zip->close();

                throw new ValidationException(\sprintf('A batch can hold at most %d invoices.', self::MAX_INVOICES));
            }
            $name = \sprintf('invoice_%05d.xml', \count($hashes) + 1);
            if (!$zip->addFromString($name, $document->xml)) {
                $zip->close();

                throw new ConfigurationException('Cannot add an invoice to the batch archive.');
            }
            $hashes[] = Digest::sha256Base64($document->xml);
        }
        if ($hashes === []) {
            $zip->close();

            throw new ValidationException('A batch needs at least one invoice.');
        }
        if (!$zip->close()) {
            throw new ConfigurationException('Cannot finish the batch archive.');
        }

        return $hashes;
    }
}
