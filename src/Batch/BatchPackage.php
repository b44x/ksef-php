<?php

declare(strict_types=1);

namespace B4x\Ksef\Batch;

use B4x\Ksef\Exception\ValidationException;

/**
 * A ZIP archive of invoices, split into parts of bounded size. The archive lives in a temporary
 * file so that large batches are never held in memory; call {@see self::dispose()} when done.
 */
final class BatchPackage
{
    /**
     * @param list<string> $invoiceHashes Base64 SHA-256 of every invoice XML in archive order, for correlating results
     */
    public function __construct(
        private readonly string $zipPath,
        public readonly string $zipHash,
        public readonly int $zipSize,
        public readonly int $partSize,
        public readonly int $partCount,
        public readonly array $invoiceHashes,
    ) {}

    /** Raw (unencrypted) bytes of one part, 1-based. */
    public function part(int $ordinal): string
    {
        if ($ordinal < 1 || $ordinal > $this->partCount) {
            throw new ValidationException(\sprintf('Batch part %d does not exist.', $ordinal));
        }

        $handle = fopen($this->zipPath, 'rb');
        if ($handle === false) {
            throw new ValidationException('The batch archive is no longer available.');
        }

        try {
            fseek($handle, ($ordinal - 1) * $this->partSize);
            $data = stream_get_contents($handle, $this->partSize);
        } finally {
            fclose($handle);
        }

        return $data !== false ? $data : throw new ValidationException('The batch archive cannot be read.');
    }

    public function dispose(): void
    {
        if (is_file($this->zipPath)) {
            unlink($this->zipPath);
        }
    }
}
