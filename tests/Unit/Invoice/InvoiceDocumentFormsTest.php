<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\InvoiceDocument;
use PHPUnit\Framework\TestCase;

final class InvoiceDocumentFormsTest extends TestCase
{
    public function testPeppolDocumentsAreRecognisedAndCheckedAgainstTheirOwnSchemas(): void
    {
        foreach ([
            'PEF' => '<Invoice xmlns="' . FormCode::PEF_INVOICE_NAMESPACE . '"/>',
            'PEF_KOR' => '<CreditNote xmlns="' . FormCode::PEF_CREDIT_NOTE_NAMESPACE . '"/>',
        ] as $label => $xml) {
            try {
                InvoiceDocument::fromXml($xml);
                self::fail('An empty UBL document cannot be valid: ' . $label);
            } catch (SerializationException $e) {
                // The schema of the recognised form was applied: it complains about missing UBL content.
                self::assertStringContainsString('does not conform to the schema', $e->getMessage(), $label);
                self::assertStringContainsString('Missing child element', $e->getMessage(), $label);
            }
        }
    }

    public function testFormCodesMatchTheOpenApiSpecification(): void
    {
        self::assertSame(['systemCode' => 'PEF (3)', 'schemaVersion' => '2-1', 'value' => 'PEF'], FormCode::pef()->toArray());
        self::assertSame(['systemCode' => 'PEF_KOR (3)', 'schemaVersion' => '2-1', 'value' => 'PEF'], FormCode::pefCorrection()->toArray());
        self::assertSame(['systemCode' => 'FA_RR (1)', 'schemaVersion' => '1-1E', 'value' => 'FA_RR'], FormCode::rr()->toArray());
    }

    public function testUnknownDocumentsAreRefused(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Unsupported invoice document');
        InvoiceDocument::fromXml('<Faktura xmlns="urn:example"/>');
    }
}
