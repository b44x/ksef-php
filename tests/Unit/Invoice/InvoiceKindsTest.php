<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\AdvanceInvoiceReference;
use B4x\Ksef\Invoice\AdvancePayment;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
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

        self::assertSame('6150.00', $invoice->totals()->gross()->toString(2));
        self::assertSame('4920.00', $invoice->amountDue()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('ROZ', $xpath->evaluate('string(//f:RodzajFaktury)'));
        self::assertSame('5000.00', $xpath->evaluate('string(//f:P_13_1)'), 'a settlement invoice reports the whole sale');
        self::assertSame('4920.00', $xpath->evaluate('string(//f:P_15)'));
        self::assertSame(self::KSEF_NUMBER, $xpath->evaluate('string(//f:FakturaZaliczkowa[1]/f:NrKSeFFaZaliczkowej)'));
        self::assertSame('1', $xpath->evaluate('string(//f:FakturaZaliczkowa[2]/f:NrKSeFZN)'));
        self::assertSame('ZAL/OUT/7', $xpath->evaluate('string(//f:FakturaZaliczkowa[2]/f:NrFaZaliczkowej)'));
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

    private function xpath(Invoice $invoice): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML(InvoiceDocument::fromInvoice($invoice, new MutableClock('2026-06-01T08:00:00+00:00'))->xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', FormCode::FA3_NAMESPACE);

        return $xpath;
    }
}
