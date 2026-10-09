<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\SystemClock;
use B4x\Ksef\Xml\SafeXml;
use B4x\Ksef\Xml\SchemaValidator;
use DOMNode;
use Psr\Clock\ClockInterface;

/**
 * A ready-to-send invoice XML together with its schema identification.
 *
 * Instances are always schema-valid: they are either produced from a typed {@see Invoice} or
 * checked when wrapping raw XML. Anything that is not representable by the typed model (advance
 * invoices, additional parties, attachments, ...) can be sent through {@see self::fromXml()}.
 */
final readonly class InvoiceDocument
{
    /** Maximum size KSeF accepts for an invoice with attachments, in bytes. */
    public const MAX_BYTES = 3_000_000;

    private function __construct(
        public string $xml,
        public FormCode $formCode,
    ) {}

    /**
     * @throws ValidationException when the invoice fails the schema or a size limit after serialization
     */
    public static function fromInvoice(
        Invoice $invoice,
        ?ClockInterface $clock = null,
        Fa3Serializer $serializer = new Fa3Serializer(),
        SchemaValidator $validator = new SchemaValidator(),
    ): self {
        $xml = $serializer->serialize($invoice, ($clock ?? new SystemClock())->now());

        return self::fromXml($xml, $validator);
    }

    /**
     * Wraps an existing FA(3) document after verifying it.
     *
     * @throws SerializationException|ValidationException
     */
    public static function fromXml(string $xml, SchemaValidator $validator = new SchemaValidator()): self
    {
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            throw new SerializationException('The invoice XML must not start with a byte order mark.');
        }
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new SerializationException(\sprintf('The invoice is larger than the KSeF limit of %d bytes.', self::MAX_BYTES));
        }
        if (!mb_check_encoding($xml, 'UTF-8')) {
            throw new SerializationException('The invoice XML must be encoded as UTF-8.');
        }

        $document = SafeXml::load($xml);
        $encoding = $document->xmlEncoding;
        if ($encoding !== null && strtoupper($encoding) !== 'UTF-8') {
            throw new SerializationException('The invoice XML must declare UTF-8 encoding.');
        }
        self::assertNoProcessingInstructions($document);

        if ($document->documentElement?->namespaceURI !== FormCode::FA3_NAMESPACE || $document->documentElement->localName !== 'Faktura') {
            throw new SerializationException('Only FA(3) invoices (namespace ' . FormCode::FA3_NAMESPACE . ') are supported.');
        }

        $validator->assertValid($xml, __DIR__ . '/../../resources/schemas/fa3/schemat_FA3_v1-0E.xsd');

        return new self($xml, FormCode::fa3());
    }

    /** SHA-256 of the XML, Base64 encoded, as KSeF expects. */
    public function hash(): string
    {
        return Digest::sha256Base64($this->xml);
    }

    public function size(): int
    {
        return \strlen($this->xml);
    }

    private static function assertNoProcessingInstructions(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                throw new SerializationException('KSeF rejects invoices containing XML processing instructions.');
            }
            self::assertNoProcessingInstructions($child);
        }
    }
}
