<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Annotations;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\CorrectionType;
use B4x\Ksef\Invoice\Fa3Serializer;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Gtu;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\MutableClock;
use B4x\Ksef\Xml\SchemaValidator;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class SerializationTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableClock('2026-06-01T08:30:00+02:00');
    }

    public function testStandardInvoiceMatchesTheOfficialSchemaAndHasExpectedValues(): void
    {
        $document = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock);
        $xpath = $this->xpath($document->xml);

        self::assertSame('FA (3)', $xpath->evaluate('string(//f:KodFormularza/@kodSystemowy)'));
        self::assertSame('2026-06-01T06:30:00Z', $xpath->evaluate('string(//f:DataWytworzeniaFa)'));
        self::assertSame('5265877635', $xpath->evaluate('string(//f:Podmiot1/f:DaneIdentyfikacyjne/f:NIP)'));
        self::assertSame('FV/2026/06/001', $xpath->evaluate('string(//f:Fa/f:P_2)'));
        self::assertSame('1500.00', $xpath->evaluate('string(//f:P_13_1)'));
        self::assertSame('345.00', $xpath->evaluate('string(//f:P_14_1)'));
        self::assertSame('149.97', $xpath->evaluate('string(//f:P_13_2)'));
        self::assertSame('12.00', $xpath->evaluate('string(//f:P_14_2)'));
        self::assertSame('2006.97', $xpath->evaluate('string(//f:P_15)'));
        self::assertSame('VAT', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('2', $xpath->evaluate('string(//f:FaWiersz[2]/f:NrWierszaFa)'));
        self::assertSame('49.99', $xpath->evaluate('string(//f:FaWiersz[2]/f:P_9A)'));
        self::assertSame('149.97', $xpath->evaluate('string(//f:FaWiersz[2]/f:P_11)'));
        self::assertSame('8', $xpath->evaluate('string(//f:FaWiersz[2]/f:P_12)'));
        self::assertEquals(FormCode::fa3(), $document->formCode);
    }

    public function testSpecialCharactersAreEscapedByTheXmlWriter(): void
    {
        $document = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock);

        self::assertStringContainsString('Consulting &amp; "support" &lt;June&gt;', $document->xml);
        self::assertSame('Consulting & "support" <June>', $this->xpath($document->xml)->evaluate('string(//f:FaWiersz[1]/f:P_7)'));
        self::assertStringNotContainsString('<?xml-', $document->xml);
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $document->xml);
    }

    public function testHashAndSizeDescribeTheExactBytes(): void
    {
        $document = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock);

        self::assertSame(base64_encode(hash('sha256', $document->xml, true)), $document->hash());
        self::assertSame(\strlen($document->xml), $document->size());
        self::assertSame($document->hash(), InvoiceDocument::fromXml($document->xml)->hash());
    }

    public function testRichInvoiceWithPaymentExemptionsAndOptionalFieldsValidates(): void
    {
        $invoice = Fixtures::builder()
            ->issuePlace('Warszawa')
            ->payment(Payment::dueOn(new DateTimeImmutable('2026-06-15'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
            ->annotations(new Annotations(cashAccounting: true, splitPayment: true, exemptionBasis: 'Art. 43 ust. 1 pkt 37 ustawy'))
            ->footer('Registered in the commercial register.')
            ->addLine((new InvoiceLine('Goods', \B4x\Ksef\Support\Decimal::of('2.5'), 'kg', \B4x\Ksef\Invoice\Money::of('10.123456', 'PLN'), VatRate::Rate5, Gtu::Gtu01, '01.11.1', '0101', '5901234123457', 'SKU-1')))
            ->addLine(InvoiceLine::of('Financial service', '1', null, '100.00', VatRate::Exempt))
            ->addLine(InvoiceLine::of('Export', '1', null, '10.00', VatRate::ZeroExport))
            ->addLine(InvoiceLine::of('Outside Poland', '1', null, '10.00', VatRate::NotSubjectOutsideTerritory))
            ->build();

        $xpath = $this->xpath(InvoiceDocument::fromInvoice($invoice, $this->clock)->xml);

        self::assertSame('Warszawa', $xpath->evaluate('string(//f:P_1M)'));
        self::assertSame('10.123456', $xpath->evaluate('string(//f:FaWiersz[1]/f:P_9A)'));
        self::assertSame('2.5', $xpath->evaluate('string(//f:FaWiersz[1]/f:P_8B)'));
        self::assertSame('25.31', $xpath->evaluate('string(//f:FaWiersz[1]/f:P_11)'));
        self::assertSame('1', $xpath->evaluate('string(//f:P_16)'));
        self::assertSame('1', $xpath->evaluate('string(//f:P_19)'));
        self::assertSame('Art. 43 ust. 1 pkt 37 ustawy', $xpath->evaluate('string(//f:P_19A)'));
        self::assertSame('6', $xpath->evaluate('string(//f:FormaPlatnosci)'));
        self::assertSame('2026-06-15', $xpath->evaluate('string(//f:TerminPlatnosci/f:Termin)'));
        self::assertSame('GTU_01', $xpath->evaluate('string(//f:FaWiersz[1]/f:GTU)'));
        self::assertSame('10.00', $xpath->evaluate('string(//f:P_13_6_3)'));
        self::assertSame('10.00', $xpath->evaluate('string(//f:P_13_8)'));
    }

    public function testBuyerIdentificationVariantsValidate(): void
    {
        $variants = [
            BuyerIdentifier::euVat('DE', '123456789'),
            BuyerIdentifier::foreign('GB123456789', 'GB'),
            BuyerIdentifier::foreign('US-TAX-1'),
            BuyerIdentifier::none(),
        ];

        foreach ($variants as $identifier) {
            $invoice = Fixtures::builder()
                ->buyer(new Buyer($identifier, 'Foreign Buyer GmbH', new Address('DE', 'Hauptstrasse 1', '10115 Berlin')))
                ->addLine(InvoiceLine::of('Service', '1', null, '10.00', VatRate::Rate23))
                ->build();

            self::assertNotSame('', InvoiceDocument::fromInvoice($invoice, $this->clock)->xml);
        }
    }

    public function testForeignCurrencyInvoiceEmitsRateAndVatInPln(): void
    {
        $invoice = Fixtures::builder()->currency('EUR')->exchangeRate('4.3210')
            ->addLine(InvoiceLine::of('Service', '1', null, '100.00', VatRate::Rate23, 'EUR'))
            ->build();

        $xpath = $this->xpath(InvoiceDocument::fromInvoice($invoice, $this->clock)->xml);

        self::assertSame('EUR', $xpath->evaluate('string(//f:KodWaluty)'));
        self::assertSame('99.38', $xpath->evaluate('string(//f:P_14_1W)'));
        self::assertSame('4.321', $xpath->evaluate('string(//f:KursWalutyZ)'));
    }

    public function testCorrectionInvoiceValidatesAndMarksTheBeforeState(): void
    {
        $invoice = Fixtures::builder()
            ->correction(new Correction(
                [new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'FV/2026/05/009', '5265877635-20260502-0100001AF629-AF'), new CorrectedInvoice(new DateTimeImmutable('2026-05-03'), 'FV/2026/05/010')],
                CorrectionType::CorrectionInvoicePeriod,
                'Quantity corrected',
            ))
            ->addLine(InvoiceLine::of('Widget', '10', 'pcs', '20.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Widget', '8', 'pcs', '20.00', VatRate::Rate23))
            ->build();

        $xpath = $this->xpath(InvoiceDocument::fromInvoice($invoice, $this->clock)->xml);

        self::assertSame('KOR', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('2', $xpath->evaluate('string(//f:TypKorekty)'));
        self::assertSame('-40.00', $xpath->evaluate('string(//f:P_13_1)'));
        self::assertSame('1', $xpath->evaluate('string(//f:FaWiersz[1]/f:StanPrzed)'));
        self::assertSame('', $xpath->evaluate('string(//f:FaWiersz[2]/f:StanPrzed)'));
        self::assertSame('5265877635-20260502-0100001AF629-AF', $xpath->evaluate('string(//f:DaneFaKorygowanej[1]/f:NrKSeFFaKorygowanej)'));
        self::assertSame('1', $xpath->evaluate('string(//f:DaneFaKorygowanej[2]/f:NrKSeFN)'));
    }

    public function testRawXmlIsVerifiedBeforeUse(): void
    {
        $valid = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock)->xml;

        self::assertSame($valid, InvoiceDocument::fromXml($valid)->xml);

        foreach ([
            'bom' => "\xEF\xBB\xBF" . $valid,
            'doctype' => str_replace('<Faktura', '<!DOCTYPE Faktura [<!ENTITY x "y">]><Faktura', $valid),
            'processing instruction' => str_replace('<Naglowek>', '<?pi data?><Naglowek>', $valid),
            'wrong namespace' => str_replace('wzor/2025/06/25/13775', 'wzor/2023/06/29/12648', $valid),
            'schema violation' => str_replace('<WariantFormularza>3</WariantFormularza>', '<WariantFormularza>9</WariantFormularza>', $valid),
            'not xml' => 'not xml',
            'empty' => '',
        ] as $label => $xml) {
            try {
                InvoiceDocument::fromXml($xml);
                self::fail('Expected SerializationException for: ' . $label);
            } catch (SerializationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSchemaViolationsListTheOffendingElements(): void
    {
        $valid = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock)->xml;
        $broken = str_replace('<P_15>2006.97</P_15>', '<P_15>abc</P_15>', $valid);

        try {
            (new SchemaValidator())->assertValid($broken, __DIR__ . '/../../../resources/schemas/fa3/schemat_FA3_v1-0E.xsd');
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertStringContainsString('P_15', implode(' ', $e->violations));
        }
    }

    public function testOversizedDocumentsAreRefused(): void
    {
        $this->expectException(SerializationException::class);
        InvoiceDocument::fromXml(str_repeat('a', InvoiceDocument::MAX_BYTES + 1));
    }

    public function testSerializerOutputIsDeterministic(): void
    {
        $serializer = new Fa3Serializer();
        $at = new DateTimeImmutable('2026-06-01T00:00:00Z');

        self::assertSame($serializer->serialize(Fixtures::standardInvoice(), $at), $serializer->serialize(Fixtures::standardInvoice(), $at));
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', FormCode::FA3_NAMESPACE);

        return $xpath;
    }
}
