<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Reader;

use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\AdvanceInvoiceReference;
use B4x\Ksef\Invoice\AdvancePayment;
use B4x\Ksef\Invoice\Annotations;
use B4x\Ksef\Invoice\Attachment;
use B4x\Ksef\Invoice\AttachmentBlock;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\CorrectionAmount;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\InvoiceType;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\PartialPayment;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\Settlement;
use B4x\Ksef\Invoice\ThirdParty;
use B4x\Ksef\Invoice\ThirdPartyRole;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Reader\InvoiceReader;
use B4x\Ksef\Status\DownloadedInvoice;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\MutableClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InvoiceReaderTest extends TestCase
{
    private const KSEF_NUMBER = '5265877635-20250826-0100001AF629-AF';

    public function testAStandardInvoiceIsReadBackAsIssued(): void
    {
        $invoice = Fixtures::builder()
            ->issuePlace('Warszawa')
            ->addLine(InvoiceLine::of('Consulting & "support" <June>', '10', 'h', '150.00', VatRate::Rate23))
            ->addLine(InvoiceLine::of('Printed manual', '3', 'pcs', '49.99', VatRate::Rate8))
            ->payment(Payment::dueOn(new DateTimeImmutable('2026-06-15'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
            ->footer('Thank you')
            ->addInfo('Project', 'Alpha', 1)
            ->build();

        $read = (new InvoiceReader())->read($this->document($invoice));

        self::assertSame('FV/2026/06/001', $read->number);
        self::assertSame('2026-06-01', $read->issueDate->format('Y-m-d'));
        self::assertSame('2026-05-31', $read->saleDate?->format('Y-m-d'));
        self::assertSame('Warszawa', $read->issuePlace);
        self::assertSame('PLN', $read->currency);
        self::assertSame(InvoiceType::Standard, $read->type);
        self::assertFalse($read->isCorrection());

        self::assertSame(Fixtures::SELLER_NIP, $read->seller->nip);
        self::assertSame('Example Seller sp. z o.o.', $read->seller->name);
        self::assertSame('ul. Prosta 1/2', $read->seller->address?->line1);
        self::assertSame('billing@seller.example', $read->seller->email);
        self::assertSame(Fixtures::BUYER_NIP, $read->buyer->taxId());
        self::assertSame('Sample Buyer S.A.', $read->buyer->name);

        self::assertCount(2, $read->lines);
        self::assertSame('Consulting & "support" <June>', $read->lines[0]->name);
        self::assertSame('10', $read->lines[0]->quantity?->toTrimmedString());
        self::assertSame('1500.00', $read->lines[0]->netAmount?->toString(2));
        self::assertSame(VatRate::Rate23, $read->lines[0]->rate());
        self::assertSame('pcs', $read->lines[1]->unit);

        self::assertSame(['1', '2'], array_map(static fn($row): string => $row->group, $read->taxSummary));
        self::assertSame($invoice->totals()->net()->toString(2), $read->totalNet()->toString(2));
        self::assertSame($invoice->totals()->vat()->toString(2), $read->totalTax()->toString(2));
        self::assertSame($invoice->totals()->gross()->toString(2), $read->total->toString(2));

        self::assertNotNull($read->payment);
        self::assertSame('2026-06-15', $read->payment->dueDates[0]->format('Y-m-d'));
        self::assertSame(PaymentMethod::BankTransfer, $read->payment->method());
        self::assertSame(['PL61109010140000071219812874'], $read->payment->bankAccounts);
        self::assertFalse($read->payment->isPaid());

        self::assertSame('Thank you', $read->footer);
        self::assertSame('Project', $read->notes[0]->key);
        self::assertSame(1, $read->notes[0]->lineNumber);
        self::assertFalse($read->hasAttachment);
    }

    public function testPartialPaymentsAnnotationsThirdPartiesAndAttachmentsAreRead(): void
    {
        $invoice = Fixtures::builder()
            ->annotations(new Annotations(splitPayment: true, exemptionBasis: 'Art. 43 ust. 1 pkt 29 lit. b ustawy o VAT'))
            ->addLine(InvoiceLine::of('Training', '1', 'h', '100.00', VatRate::Exempt))
            ->addThirdParty(ThirdParty::of(ThirdPartyRole::Recipient, \B4x\Ksef\Invoice\BuyerIdentifier::nip(Nip::of('5265877635')), 'Branch office'))
            ->payment(Payment::partlyPaid([new PartialPayment(Money::pln('40.00'), new DateTimeImmutable('2026-05-20'), PaymentMethod::Card)]))
            ->attachment(new Attachment([new AttachmentBlock(['Period' => '2026-05'], 'Statement', ['Services rendered.'])]))
            ->build();

        $read = (new InvoiceReader())->read($this->document($invoice));

        self::assertTrue($read->annotations->splitPayment);
        self::assertSame('Art. 43 ust. 1 pkt 29 lit. b ustawy o VAT', $read->annotations->exemptionBasis);
        self::assertSame('Branch office', $read->thirdParties[0]->name);
        self::assertSame(ThirdPartyRole::Recipient->value, $read->thirdParties[0]->roleCode);
        self::assertNotNull($read->payment);
        self::assertSame('40.00', $read->payment->partialPayments[0]->amount->toString(2));
        self::assertSame(2, $read->payment->partialPayments[0]->methodCode);
        self::assertFalse($read->payment->partialPaymentsComplete, 'A part of the invoice is paid, not all of it.');
        self::assertTrue($read->hasAttachment);
    }

    public function testCorrectionsCarryTheCorrectedInvoicesAndTheBeforeLines(): void
    {
        $invoice = Invoice::builder()
            ->number('KOR/1')->issueDate('2026-06-10')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-06-01'), 'FV/1', self::KSEF_NUMBER)], reason: 'Price change'))
            ->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Widget', '1', 'szt.', '90.00', VatRate::Rate23))
            ->build();

        $read = (new InvoiceReader())->read($this->document($invoice));

        self::assertTrue($read->isCorrection());
        self::assertNotNull($read->correction);
        self::assertSame('Price change', $read->correction->reason);
        self::assertSame('FV/1', $read->correction->correctedInvoices[0]->number);
        self::assertSame(self::KSEF_NUMBER, $read->correction->correctedInvoices[0]->ksefNumber);
        self::assertTrue($read->lines[0]->before);
        self::assertFalse($read->lines[1]->before);
        self::assertSame('-10.00', $read->totalNet()->toString(2), 'The header holds the differences, as issued.');
    }

    public function testACollectiveCorrectionHasAPeriodAndNoLines(): void
    {
        $invoice = Invoice::builder()
            ->number('KOR/Q2')->issueDate('2026-07-05')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-04-10'), 'FV/1'), new CorrectedInvoice(new DateTimeImmutable('2026-05-10'), 'FV/2')], period: 'Q2 2026', amounts: [CorrectionAmount::of(VatRate::Rate23, '-100.00', '-23.00')]))
            ->build();

        $read = (new InvoiceReader())->read($this->document($invoice));

        self::assertNotNull($read->correction);
        self::assertSame('Q2 2026', $read->correction->period);
        self::assertCount(2, $read->correction->correctedInvoices);
        self::assertSame([], $read->lines);
        self::assertSame('-123.00', $read->total->toString(2));
    }

    public function testAdvanceAndSettlementInvoices(): void
    {
        $advance = Invoice::builder()
            ->number('ZAL/1')->issueDate('2026-05-20')->seller(Fixtures::seller())->buyer(Fixtures::buyer())
            ->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, new DateTimeImmutable('2026-05-20')))
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23))
            ->build();
        $settlement = Fixtures::builder()
            ->settlement(new Settlement([AdvanceInvoiceReference::ksef(self::KSEF_NUMBER)], Money::pln('1230.00')))
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23))
            ->build();

        $readAdvance = (new InvoiceReader())->read($this->document($advance));
        $readSettlement = (new InvoiceReader())->read($this->document($settlement));

        self::assertSame(InvoiceType::Advance, $readAdvance->type);
        self::assertSame([], $readAdvance->lines, 'An advance invoice describes the order, not lines.');
        self::assertSame('6150.00', $readAdvance->orderValue?->toString(2));
        self::assertSame('1230.00', $readAdvance->total->toString(2));
        self::assertSame(InvoiceType::Settlement, $readSettlement->type);
        self::assertSame([self::KSEF_NUMBER], $readSettlement->advanceInvoices);
        self::assertSame('4920.00', $readSettlement->total->toString(2));
    }

    public function testForeignCurrencyKeepsTheRateAndThePlnTax(): void
    {
        $invoice = Fixtures::builder()->currency('EUR')->exchangeRate('4.3210')
            ->addLine(InvoiceLine::of('Licence', '1', 'szt.', '1000.00', VatRate::Rate23, 'EUR'))
            ->build();

        $read = (new InvoiceReader())->read($this->document($invoice));

        self::assertSame('EUR', $read->currency);
        self::assertSame('4.321', $read->exchangeRate?->toTrimmedString());
        self::assertNotNull($read->taxSummary[0]->taxPln);
        self::assertSame('230.00', $read->taxSummary[0]->tax?->toString(2));
        self::assertSame('993.83', $read->taxSummary[0]->taxPln->toString(2));
    }

    public function testGrossPricedLinesAreReadWithTheirOwnFields(): void
    {
        $invoice = Fixtures::builder()->addLine(InvoiceLine::of('Widget', '2', 'szt.', '50.00', VatRate::Rate23))->build();
        $xml = $this->document($invoice)->xml;
        // Re-express the line the way a gross-priced issuer writes it: P_9B, P_11A and P_11Vat instead of P_9A and P_11.
        $xml = str_replace(['<P_9A>50.00</P_9A>', '<P_11>100.00</P_11>'], ['<P_9B>61.50</P_9B>', '<P_11A>123.00</P_11A><P_11Vat>23.00</P_11Vat>'], $xml);

        $read = (new InvoiceReader())->read($xml);

        self::assertNull($read->lines[0]->unitPriceNet);
        self::assertSame('61.50', $read->lines[0]->unitPriceGross?->toString(2));
        self::assertNull($read->lines[0]->netAmount);
        self::assertSame('123.00', $read->lines[0]->grossAmount?->toString(2));
        self::assertSame('23.00', $read->lines[0]->vatAmount?->toString(2));
    }

    public function testADownloadedInvoiceCanBeRead(): void
    {
        $xml = $this->document(Fixtures::standardInvoice())->xml;

        $read = (new DownloadedInvoice(self::KSEF_NUMBER, $xml, ''))->read();

        self::assertSame('FV/2026/06/001', $read->number);
        self::assertSame($xml, $read->document->xml, 'The original XML stays available.');
    }

    public function testOtherFormsAndBrokenInputAreRefused(): void
    {
        $pef = InvoiceDocument::fromXml(str_replace(['{{DATE}}', '{{NUMBER}}'], ['2026-06-01', 'PEF/1'], (string) file_get_contents(__DIR__ . '/../../../examples/fixtures/pef-invoice.xml')));
        try {
            (new InvoiceReader())->read($pef);
            self::fail('A Peppol document is not an FA(3) invoice.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Only FA(3)', $e->getMessage());
        }

        $this->expectException(SerializationException::class);
        (new InvoiceReader())->read('<not-an-invoice/>');
    }

    private function document(Invoice $invoice): InvoiceDocument
    {
        return InvoiceDocument::fromInvoice($invoice, new MutableClock('2026-06-01T08:00:00+00:00'));
    }
}
