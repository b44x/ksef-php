<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\AdvanceAmount;
use B4x\Ksef\Invoice\AdvanceInvoiceReference;
use B4x\Ksef\Invoice\AdvancePayment;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\CorrectionAmount;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\InvoiceType;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\Settlement;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\MutableClock;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class InvoiceKindsTest extends TestCase
{
    private const KSEF_NUMBER = '5265877635-20250826-0100001AF629-AF';

    public function testAdvanceInvoiceTakesTheTaxOutOfTheGrossPaymentAndDescribesTheOrder(): void
    {
        $invoice = Invoice::builder()
            ->number('ZAL/2026/05/001')->issueDate('2026-05-20')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, new DateTimeImmutable('2026-05-20')))
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))
            ->build();

        $totals = $invoice->totals();
        self::assertSame('1000.00', $totals->net()->toString(2));
        self::assertSame('230.00', $totals->vat()->toString(2), '1230 * 23 / 123');
        self::assertSame('1230.00', $invoice->amountDue()->toString(2));
        self::assertSame('6150.00', $invoice->orderValue()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('ZAL', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('2026-05-20', $xpath->evaluate('string(//f:P_6)'), 'the payment date replaces the sale date');
        self::assertSame('1000.00', $xpath->evaluate('string(//f:P_13_1)'));
        self::assertSame('230.00', $xpath->evaluate('string(//f:P_14_1)'));
        self::assertSame('1230.00', $xpath->evaluate('string(//f:P_15)'));
        self::assertSame(0.0, $xpath->evaluate('count(//f:FaWiersz)'), 'an advance invoice has no invoice lines');
        self::assertSame('6150.00', $xpath->evaluate('string(//f:Zamowienie/f:WartoscZamowienia)'));
        self::assertSame('1150.00', $xpath->evaluate('string(//f:ZamowienieWiersz/f:P_11VatZ)'));
        self::assertSame('Custom software', $xpath->evaluate('string(//f:ZamowienieWiersz/f:P_7Z)'));
    }

    public function testSettlementInvoiceSubtractsTheAdvancesFromTheAmountDue(): void
    {
        $invoice = Fixtures::builder()
            ->settlement(new Settlement([AdvanceInvoiceReference::ksef(self::KSEF_NUMBER), AdvanceInvoiceReference::external('ZAL/OUT/7')], Money::pln('1230.00')))
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))
            ->build();

        self::assertSame('6150.00', $invoice->saleTotals()->gross()->toString(2), 'the lines sell 5000.00 net + 1150.00 VAT');
        self::assertSame('4000.00', $invoice->totals()->net()->toString(2), 'the advance of 1230.00 gross contained 1000.00 net');
        self::assertSame('920.00', $invoice->totals()->vat()->toString(2), 'and 230.00 VAT');
        self::assertSame('4920.00', $invoice->amountDue()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('ROZ', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('4000.00', $xpath->evaluate('string(//f:P_13_1)'), 'P_13 and P_14 show only what remains to be paid (art. 106f(3))');
        self::assertSame('920.00', $xpath->evaluate('string(//f:P_14_1)'));
        self::assertSame('4920.00', $xpath->evaluate('string(//f:P_15)'));
        self::assertSame('5000.00', $xpath->evaluate('string(//f:FaWiersz/f:P_11)'), 'the lines carry the full values');
        self::assertSame(self::KSEF_NUMBER, $xpath->evaluate('string(//f:FakturaZaliczkowa[1]/f:NrKSeFFaZaliczkowej)'));
        self::assertSame('1', $xpath->evaluate('string(//f:FakturaZaliczkowa[2]/f:NrKSeFZN)'));
        self::assertSame('ZAL/OUT/7', $xpath->evaluate('string(//f:FakturaZaliczkowa[2]/f:NrFaZaliczkowej)'));
    }

    public function testCorrectionOfAnAdvanceInvoiceCarriesTheDifferenceAndTheOrderBeforeAndAfter(): void
    {
        $invoice = Invoice::builder()
            ->number('KZAL/2026/05/001')->issueDate('2026-05-25')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-05-20'), 'ZAL/2026/05/001', self::KSEF_NUMBER)], reason: 'Smaller order', amountBefore: Money::pln('1230.00')))
            ->advance(new AdvancePayment(Money::pln('-615.00'), VatRate::Rate23, new DateTimeImmutable('2026-05-20')))
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '4500.00', VatRate::Rate23))
            ->build();

        self::assertSame(InvoiceType::AdvanceCorrection, $invoice->type);
        self::assertSame('-500.00', $invoice->totals()->net()->toString(2));
        self::assertSame('-115.00', $invoice->totals()->vat()->toString(2), '-615 * 23 / 123');
        self::assertSame('-615.00', $invoice->amountDue()->toString(2));
        self::assertSame('5535.00', $invoice->orderValue()->toString(2), 'the order is worth what the lines say after the correction');

        $xpath = $this->xpath($invoice);
        self::assertSame('KOR_ZAL', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('1230.00', $xpath->evaluate('string(//f:P_15ZK)'), 'the payment documented before the correction');
        self::assertSame('Smaller order', $xpath->evaluate('string(//f:PrzyczynaKorekty)'));
        self::assertSame(0.0, $xpath->evaluate('count(//f:FaWiersz)'));
        self::assertSame(2.0, $xpath->evaluate('count(//f:ZamowienieWiersz)'));
        self::assertSame('1', $xpath->evaluate('string(//f:ZamowienieWiersz[1]/f:StanPrzedZ)'));
        self::assertSame('', $xpath->evaluate('string(//f:ZamowienieWiersz[2]/f:StanPrzedZ)'));
    }

    public function testCorrectionOfASettlementInvoiceCombinesBeforeAfterLinesWithTheAdvances(): void
    {
        $invoice = Invoice::builder()
            ->number('KROZ/2026/06/001')->issueDate('2026-06-02')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-06-01'), 'ROZ/2026/06/001')]))
            ->settlement(new Settlement([AdvanceInvoiceReference::ksef(self::KSEF_NUMBER)], Money::pln('0.00')))
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '4000.00', VatRate::Rate23))
            ->build();

        self::assertSame(InvoiceType::SettlementCorrection, $invoice->type);
        self::assertSame('-1230.00', $invoice->amountDue()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('KOR_ROZ', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('-1000.00', $xpath->evaluate('string(//f:P_13_1)'));
        self::assertSame(self::KSEF_NUMBER, $xpath->evaluate('string(//f:FakturaZaliczkowa/f:NrKSeFFaZaliczkowej)'));
        self::assertSame(2.0, $xpath->evaluate('count(//f:FaWiersz)'));
    }

    public function testTheAmountBeforeACorrectionBelongsToAdvanceAndSettlementCorrectionsOnly(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('P_15ZK');
        Fixtures::builder()
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'FV/1')], amountBefore: Money::pln('10.00')))
            ->addLine(InvoiceLine::of('Widget', '10', 'szt.', '10.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Widget', '8', 'szt.', '10.00', VatRate::Rate23))
            ->build();
    }

    public function testSettlementWithSeveralRatesNeedsTheRatesOfTheAdvances(): void
    {
        $lines = [InvoiceLine::of('Software', '1', 'szt.', '1000.00', VatRate::Rate23), InvoiceLine::of('Books', '1', 'szt.', '100.00', VatRate::Rate5)];
        $build = static fn(Settlement $settlement): Invoice => Fixtures::builder()->settlement($settlement)->addLine($lines[0])->addLine($lines[1])->build();

        try {
            $build(new Settlement([AdvanceInvoiceReference::external('ZAL/1')], Money::pln('500.00')));
            self::fail('Expected ValidationException: the rate of the advances is ambiguous');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Give the VAT rate of the advances', implode(' ', $e->violations));
        }

        $invoice = $build(new Settlement([AdvanceInvoiceReference::external('ZAL/1')], Money::pln('1230.00'), advanceAmounts: [new AdvanceAmount(Money::pln('1230.00'), VatRate::Rate23)]));
        self::assertSame('0.00', $invoice->totals()->buckets[0]->net->toString(2), 'the whole 23% sale was paid in advance');
        self::assertSame('100.00', $invoice->totals()->buckets[1]->net->toString(2), 'the 5% part is untouched');
        self::assertSame('105.00', $invoice->amountDue()->toString(2));
    }

    public function testCorrectionOfASettlementWithUnchangedAdvancesReportsTheDifferenceOfTheSale(): void
    {
        $invoice = Fixtures::builder()
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-06-01'), 'ROZ/1')], amountBefore: Money::pln('4920.00')))
            ->settlement(new Settlement([AdvanceInvoiceReference::ksef(self::KSEF_NUMBER)], Money::pln('0.00')))
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '4000.00', VatRate::Rate23))
            ->build();

        self::assertSame('-1000.00', $invoice->totals()->net()->toString(2));
        self::assertSame('-1230.00', $invoice->amountDue()->toString(2));
    }

    public function testSimplifiedInvoiceIsLimitedAndNeedsTheBuyersNip(): void
    {
        $line = InvoiceLine::of('Coffee', '2', 'szt.', '10.00', VatRate::Rate23);
        $invoice = Fixtures::builder()->simplified()->addLine($line)->build();
        self::assertSame('UPR', $this->xpath($invoice)->evaluate('string(//f:RodzajFaktury)'));

        try {
            Fixtures::builder()->simplified()->addLine(InvoiceLine::of('Laptop', '1', 'szt.', '400.00', VatRate::Rate23))->build();
            self::fail('Expected ValidationException (over 450 PLN)');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cannot exceed 450 PLN', implode(' ', $e->violations));
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('identifies the buyer by NIP');
        Fixtures::builder()->simplified()->buyer(new Buyer(BuyerIdentifier::none(), 'Consumer'))->addLine($line)->build();
    }

    public function testKindSpecificDataMustMatchTheKind(): void
    {
        $line = InvoiceLine::of('A', '1', null, '1.00', VatRate::Rate23);

        $violations = [];
        foreach ([
            'advance missing' => fn() => Fixtures::builder()->advance(new AdvancePayment(Money::pln('1.00'), VatRate::Rate23, new DateTimeImmutable('2026-05-01')))->saleDate('2026-05-01')->addLine($line)->build(),
            'settlement too large' => fn() => Fixtures::builder()->settlement(new Settlement([AdvanceInvoiceReference::external('X')], Money::pln('999.00')))->addLine($line)->build(),
            'bad ksef number' => fn() => Fixtures::builder()->settlement(new Settlement([AdvanceInvoiceReference::ksef('5265877635-20250826-0100001AF629-00')], Money::pln('0.50')))->addLine($line)->build(),
            'advance in wrong currency' => fn() => Fixtures::builder()->advance(new AdvancePayment(Money::of('1.00', 'EUR'), VatRate::Rate23, new DateTimeImmutable('2026-05-01')))->addLine($line)->build(),
        ] as $label => $build) {
            try {
                $build();
                self::fail('Expected a ValidationException for: ' . $label);
            } catch (ValidationException $e) {
                $violations[$label] = implode(' ', $e->violations);
            }
        }

        self::assertStringContainsString('do not set a sale date', $violations['advance missing']);
        self::assertStringContainsString('between zero and the invoice total', $violations['settlement too large']);
        self::assertStringContainsString('not a valid KSeF number', $violations['bad ksef number']);
        self::assertStringContainsString('currency differs', $violations['advance in wrong currency']);
    }

    public function testCollectiveCorrectionHasAPeriodAndAmountsPerRateButNoLines(): void
    {
        $invoice = Invoice::builder()
            ->number('KOR/2026/Q2')->issueDate('2026-07-05')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->correction(new Correction(
                [new CorrectedInvoice(new DateTimeImmutable('2026-04-10'), 'FV/1'), new CorrectedInvoice(new DateTimeImmutable('2026-05-10'), 'FV/2')],
                reason: 'Quarterly volume discount',
                period: '2026-04-01 - 2026-06-30',
                amounts: [CorrectionAmount::of(VatRate::Rate23, '-100.00', '-23.00')],
            ))
            ->build();

        self::assertTrue($invoice->isCollectiveCorrection());
        self::assertSame('-100.00', $invoice->totals()->net()->toString(2));
        self::assertSame('-123.00', $invoice->amountDue()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('KOR', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('2026-04-01 - 2026-06-30', $xpath->evaluate('string(//f:OkresFaKorygowanej)'));
        self::assertSame('-100.00', $xpath->evaluate('string(//f:P_13_1)'));
        self::assertSame('-23.00', $xpath->evaluate('string(//f:P_14_1)'));
        self::assertSame('-123.00', $xpath->evaluate('string(//f:P_15)'));
        self::assertSame(0.0, $xpath->evaluate('count(//f:FaWiersz)'));
    }

    public function testCollectiveCorrectionRulesAreChecked(): void
    {
        $corrected = [new CorrectedInvoice(new DateTimeImmutable('2026-04-10'), 'FV/1')];
        $amount = [CorrectionAmount::of(VatRate::Rate23, '-100.00', '-23.00')];
        $line = InvoiceLine::of('A', '1', null, '1.00', VatRate::Rate23);

        $cases = [
            'amounts without period' => [fn() => Fixtures::builder()->correction(new Correction($corrected, amounts: $amount))->build(), 'period'],
            'period without amounts' => [fn() => Fixtures::builder()->correction(new Correction($corrected, period: 'Q2'))->build(), 'amounts'],
            'with lines' => [fn() => Fixtures::builder()->correction(new Correction($corrected, period: 'Q2', amounts: $amount))->addLine($line)->build(), 'line'],
            'taxed rate without vat' => [fn() => Fixtures::builder()->correction(new Correction($corrected, period: 'Q2', amounts: [CorrectionAmount::of(VatRate::Rate23, '-100.00')]))->build(), 'tax'],
        ];
        foreach ($cases as $label => [$build, $needle]) {
            try {
                $build();
                self::fail('Expected a ValidationException for: ' . $label);
            } catch (ValidationException $e) {
                self::assertStringContainsStringIgnoringCase($needle, implode(' ', $e->violations), $label);
            }
        }
    }

    private function xpath(Invoice $invoice): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML(InvoiceDocument::fromInvoice($invoice, new MutableClock('2026-06-01T08:00:00+00:00'))->xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', FormCode::FA3_NAMESPACE);

        return $xpath;
    }
}
