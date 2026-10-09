<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Rr\RrSerializer;
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
     * @throws ValidationException when the invoice fails the schema or a size limit after serialization
     */
    public static function fromRrInvoice(
        RrInvoice $invoice,
        ?ClockInterface $clock = null,
        RrSerializer $serializer = new RrSerializer(),
        SchemaValidator $validator = new SchemaValidator(),
    ): self {
        $xml = $serializer->serialize($invoice, ($clock ?? new SystemClock())->now());

        return self::fromXml($xml, $validator);
    }

    /**
     * Wraps an existing document after verifying it against the bundled schema: FA(3), FA_RR (1), PEF (3) or
     * PEF_KOR (3). The form is recognised from the root element. FA(2) is not supported.
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

        $schemas = __DIR__ . '/../../resources/schemas';
        $element = $document->documentElement ?? throw new SerializationException('The invoice XML has no root element.');
        $root = ($element->namespaceURI ?? '') . '#' . $element->localName;
        [$schema, $formCode] = match ($root) {
            FormCode::FA3_NAMESPACE . '#Faktura' => [$schemas . '/fa3/schemat_FA3_v1-0E.xsd', FormCode::fa3()],
            FormCode::RR_NAMESPACE . '#Faktura' => [$schemas . '/rr/schemat_FA_RR1_v1-1E.xsd', FormCode::rr()],
            FormCode::PEF_INVOICE_NAMESPACE . '#Invoice' => [$schemas . '/pef/Schemat_PEF3_v2-1.xsd', FormCode::pef()],
            FormCode::PEF_CREDIT_NOTE_NAMESPACE . '#CreditNote' => [$schemas . '/pef/Schemat_PEF_KOR3_v2-1.xsd', FormCode::pefCorrection()],
            default => throw new SerializationException('Unsupported invoice document. Supported: FA(3) (namespace ' . FormCode::FA3_NAMESPACE . '), FA_RR (1) (namespace ' . FormCode::RR_NAMESPACE . '), PEF (3) (UBL Invoice-2) and PEF_KOR (3) (UBL CreditNote-2).'),
        };

        $validator->assertValid($xml, $schema);

        return new self($xml, $formCode);
    }

    /**
     * Whether the document carries a structured attachment (`Zalacznik`). KSeF accepts such invoices only in batch
     * sessions (and in the interactive session for a technical correction of an offline invoice).
     */
    public function hasAttachment(): bool
    {
        return $this->formCode->equals(FormCode::fa3())
            && SafeXml::load($this->xml)->getElementsByTagNameNS(FormCode::FA3_NAMESPACE, 'Zalacznik')->length > 0;
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
