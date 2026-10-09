<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Rr;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Rr\RrCorrection;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Rr\RrLine;
use B4x\Ksef\Rr\RrParty;
use B4x\Ksef\Rr\RrPayment;
use B4x\Ksef\Rr\RrRate;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\MutableClock;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class RrInvoiceTest extends TestCase
{
    public function testTotalsAreSummedFromRoundedLinesAndTheRefundIsAddedToThePrice(): void
    {
        $invoice = $this->builder()
            ->addLine(RrLine::of('Wheat', 'kg', '1500', 'class A', '1.20', RrRate::Rate7))
            ->addLine(RrLine::of('Harvesting service', 'h', '10', 'standard', '99.99', RrRate::Rate6_5))
            ->payment(RrPayment::transfer('PL61109010140000071219812874'))
            ->build();

        self::assertSame('2799.90', $invoice->value()->toString(2), '1800.00 + 999.90');
        self::assertSame('190.99', $invoice->refund()->toString(2), '126.00 + 64.99 (999.90 * 6.5% = 64.9935)');
        self::assertSame('2990.89', $invoice->total()->toString(2));
        self::assertSame('dwa tysiące dziewięćset dziewięćdziesiąt złotych osiemdziesiąt dziewięć groszy', $invoice->totalInWords());

        $xpath = $this->xpath($invoice);
        self::assertSame('VAT_RR', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('FA_RR (1)', $xpath->evaluate('string(//f:KodFormularza/@kodSystemowy)'));
        self::assertSame('2799.90', $xpath->evaluate('string(//f:P_11_1)'));
        self::assertSame('190.99', $xpath->evaluate('string(//f:P_11_2)'));
        self::assertSame('2990.89', $xpath->evaluate('string(//f:P_12_1)'));
        self::assertSame('6.5', $xpath->evaluate('string(//f:FakturaRRWiersz[2]/f:P_9)'));
        self::assertSame('1064.89', $xpath->evaluate('string(//f:FakturaRRWiersz[2]/f:P_11)'));
        self::assertSame('PL61109010140000071219812874', $xpath->evaluate('string(//f:RachunekBankowy1/f:NrRB)'));
    }

    public function testACorrectionSubtractsTheBeforeLinesAndCarriesTheCorrectedInvoice(): void
    {
        $invoice = $this->builder()
            ->correction(new RrCorrection([new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'RR/2026/05/001')], reason: 'Lower quantity'))
            ->addLine(RrLine::of('Wheat', 'kg', '1500', 'class A', '1.20', RrRate::Rate7)->asBefore())
            ->addLine(RrLine::of('Wheat', 'kg', '1400', 'class A', '1.20', RrRate::Rate7))
            ->build();

        self::assertSame('-120.00', $invoice->value()->toString(2));
        self::assertSame('-8.40', $invoice->refund()->toString(2));
        self::assertSame('minus sto dwadzieścia osiem złotych czterdzieści groszy', $invoice->totalInWords());

        $xpath = $this->xpath($invoice);
        self::assertSame('KOR_VAT_RR', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('1', $xpath->evaluate('string(//f:FakturaRRWiersz[1]/f:StanPrzed)'));
        self::assertSame('RR/2026/05/001', $xpath->evaluate('string(//f:DaneFaKorygowanej/f:NrFaKorygowanej)'));
    }

    public function testRawRrXmlIsRecognisedAndVerified(): void
    {
        $xml = InvoiceDocument::fromRrInvoice($this->builder()->addLine(RrLine::of('Wheat', 'kg', '10', 'A', '1.00', RrRate::Rate7))->build(), new MutableClock('2026-06-01T08:00:00+00:00'))->xml;

        self::assertTrue(InvoiceDocument::fromXml($xml)->formCode->equals(FormCode::rr()));
    }

    public function testInvalidDataIsReportedCompletely(): void
    {
        try {
            $this->builder()
                ->addLine(new RrLine('', 'kg', \B4x\Ksef\Support\Decimal::of('0'), 'A', \B4x\Ksef\Support\Decimal::of('1'), RrRate::Rate7))
                ->buyer(new RrParty(Nip::of('5265877635'), 'Same', new Address('PL', 'x')))
                ->supplier(new RrParty(Nip::of('5265877635'), 'Same', new Address('PL', 'x')))
                ->build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $text = implode(' ', $e->violations);
            self::assertStringContainsString('name must not be empty', $text);
            self::assertStringContainsString('quantity must be positive', $text);
            self::assertStringContainsString('must be different entities', $text);
        }
    }

    private function builder(): \B4x\Ksef\Rr\RrInvoiceBuilder
    {
        return RrInvoice::builder()
            ->number('RR/2026/06/001')->issueDate('2026-06-01')->purchaseDate('2026-05-30')
            ->supplier(new RrParty(Nip::of('1111111111'), 'Jan Rolnik', new Address('PL', 'Wieś 1', '00-001 Wieś')))
            ->buyer(new RrParty(Nip::of('5265877635'), 'Skup Zbóż sp. z o.o.', new Address('PL', 'ul. Skupowa 2', '00-002 Miasto'), email: 'skup@example.com'));
    }

    private function xpath(RrInvoice $invoice): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML(InvoiceDocument::fromRrInvoice($invoice, new MutableClock('2026-06-01T08:00:00+00:00'))->xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', FormCode::RR_NAMESPACE);

        return $xpath;
    }
}
