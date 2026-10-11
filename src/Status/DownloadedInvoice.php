<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Reader\InvoiceReader;
use B4x\Ksef\Reader\ReceivedInvoice;

/** An invoice XML retrieved from KSeF, with the hash KSeF reported for it. */
final readonly class DownloadedInvoice
{
    public function __construct(
        public string $ksefNumber,
        public string $xml,
        public string $hash,
    ) {}

    public function verifyHash(): bool
    {
        return hash_equals($this->hash, Digest::sha256Base64($this->xml));
    }

    /**
     * The invoice as typed data (FA(3) only): parties, lines, tax summary, payment, corrections. The XML is verified
     * against the schema first.
     *
     * @throws \B4x\Ksef\Exception\ValidationException when the document is not a valid FA(3) invoice
     */
    public function read(): ReceivedInvoice
    {
        return (new InvoiceReader())->read($this->xml);
    }
}
