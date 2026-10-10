<?php

declare(strict_types=1);

namespace B4x\Ksef\Offline;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Qr\OfflineCertificate;
use B4x\Ksef\Qr\VerificationLinks;
use Psr\Clock\ClockInterface;

/**
 * Issues invoices in offline mode: serialises and verifies the invoice, then builds KOD I and KOD II with the
 * seller's KSeF *Offline* certificate. Nothing here talks to KSeF, so it works when KSeF is unreachable.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final readonly class OfflineIssuer
{
    private VerificationLinks $links;

    public function __construct(
        Environment $environment,
        private ContextIdentifier $context,
        private OfflineCertificate $certificate,
        private ?ClockInterface $clock = null,
    ) {
        $this->links = new VerificationLinks($environment);
    }

    public function issue(Invoice $invoice): OfflineInvoice
    {
        $document = InvoiceDocument::fromInvoice($invoice, $this->clock);

        return new OfflineInvoice(
            $document,
            $this->links->invoiceUrl($invoice->seller->nip, $invoice->issueDate, $document),
            $this->links->certificateUrl($this->context, $invoice->seller->nip, $document->hash(), $this->certificate),
            $this->links->label(null),
        );
    }
}
