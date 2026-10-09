<?php

declare(strict_types=1);

namespace B4x\Ksef\Offline;

use B4x\Ksef\Invoice\InvoiceDocument;

/**
 * An invoice issued in offline mode: the XML to deliver to KSeF later, and the two QR code links to print on
 * the visualisation handed to the buyer (until a KSeF number exists, the caption under KOD I is "OFFLINE").
 *
 * Deliver it with `KsefClient::sendOfflineInvoice()` by the deadline of the offline mode you are in
 * (see docs/OFFLINE.md).
 */
final readonly class OfflineInvoice
{
    public function __construct(
        public InvoiceDocument $document,
        /** KOD I: verifies the invoice in KSeF (by the invoice hash). */
        public string $verificationUrl,
        /** KOD II: confirms the issuer with the offline certificate. */
        public string $issuerUrl,
        public string $caption = 'OFFLINE',
    ) {}
}
