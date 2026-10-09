<?php

declare(strict_types=1);

namespace B4x\Ksef\Session;

use B4x\Ksef\Invoice\Fa3Serializer;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Xml\SchemaValidator;
use Psr\Clock\ClockInterface;

/**
 * Turns the accepted input kinds (typed invoice, verified document, raw XML) into a verified document.
 *
 * @internal
 */
final class InvoiceFactory
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Fa3Serializer $serializer = new Fa3Serializer(),
        private readonly SchemaValidator $validator = new SchemaValidator(),
    ) {}

    public function document(Invoice|InvoiceDocument|string $invoice): InvoiceDocument
    {
        return match (true) {
            $invoice instanceof InvoiceDocument => $invoice,
            $invoice instanceof Invoice => InvoiceDocument::fromInvoice($invoice, $this->clock, $this->serializer, $this->validator),
            default => InvoiceDocument::fromXml($invoice, $this->validator),
        };
    }
}
