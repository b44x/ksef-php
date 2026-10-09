<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\AdditionalSettlement;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Adjustment;
use B4x\Ksef\Invoice\Annotations;
use B4x\Ksef\Invoice\Attachment;
use B4x\Ksef\Invoice\AttachmentBlock;
use B4x\Ksef\Invoice\AttachmentColumn;
use B4x\Ksef\Invoice\AttachmentTable;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\CargoType;
use B4x\Ksef\Invoice\Carrier;
use B4x\Ksef\Invoice\ColumnType;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\DocumentReference;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\LineProcedure;
use B4x\Ksef\Invoice\MarginScheme;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\NewMeansOfTransport;
use B4x\Ksef\Invoice\NewTransportSupply;
use B4x\Ksef\Invoice\PartialPayment;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\TransactionTerms;
use B4x\Ksef\Invoice\Transport;
use B4x\Ksef\Invoice\TransportType;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\MutableClock;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class InvoiceExtrasTest extends TestCase
{
    public function testLineDiscountsDatesProceduresAndExciseAreSerializedInSchemaOrder(): void
    {
        $line = InvoiceLine::of('Beer', '10', 'l', '10.00', VatRate::Rate23)->withDiscount('5.00')->deliveredOn(new DateTimeImmutable('2026-05-30'))->withProcedure(LineProcedure::BSpv)->withExcise('1.20');
        $invoice = Fixtures::builder()->addLine($line)->build();

        self::assertSame('95.00', $line->netAmount()->toString(2), '10 x 10.00 - 5.00');
        self::assertSame('95.00', $invoice->totals()->net()->toString(2));

        $xpath = $this->xpath($invoice);
        self::assertSame('5.00', $xpath->evaluate('string(//f:FaWiersz/f:P_10)'));
        self::assertSame('95.00', $xpath->evaluate('string(//f:FaWiersz/f:P_11)'));
        self::assertSame('2026-05-30', $xpath->evaluate('string(//f:FaWiersz/f:P_6A)'));
        self::assertSame('B_SPV', $xpath->evaluate('string(//f:FaWiersz/f:Procedura)'));
        self::assertSame('1.20', $xpath->evaluate('string(//f:FaWiersz/f:KwotaAkcyzy)'));
    }

    public function testInvoiceLevelExtrasPassTheSchema(): void
    {
        $invoice = Fixtures::builder()
            ->annotations(new Annotations(marginScheme: MarginScheme::UsedGoods, triangular: true, relatedParties: true, invoiceUnderArt109: true, exciseRefund: true))
            ->addLine(InvoiceLine::of('Widget', '2', 'szt.', '50.00', VatRate::Rate23))
            ->addInfo('Project', 'Alpha')->addInfo('Delivery note', 'DN-7', 1)
            ->addWarehouseDocument('WZ/1/2026')
            ->additionalSettlement(new AdditionalSettlement([Adjustment::of('10.00', 'Packaging deposit')], [Adjustment::of('300.00', 'Prepayment')]))
            ->payment(new Payment(PaymentMethod::BankTransfer, null, [new DateTimeImmutable('2026-07-01')], ['PL61109010140000071219812874'], skontoConditions: 'Pay within 7 days', skontoAmount: '2 %'))
            ->terms(new TransactionTerms([new DocumentReference('U-1', new DateTimeImmutable('2026-01-02'))], [new DocumentReference('Z-9')], ['LOT-1'], 'DAP'))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('1', $xpath->evaluate('string(//f:Adnotacje/f:PMarzy/f:P_PMarzy)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Adnotacje/f:PMarzy/f:P_PMarzy_3_1)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Adnotacje/f:P_23)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Fa/f:TP)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Fa/f:FP)'));
        self::assertSame('Alpha', $xpath->evaluate('string(//f:DodatkowyOpis[1]/f:Wartosc)'));
        self::assertSame('1', $xpath->evaluate('string(//f:DodatkowyOpis[2]/f:NrWiersza)'));
        self::assertSame('WZ/1/2026', $xpath->evaluate('string(//f:Fa/f:WZ)'));
        self::assertSame('10.00', $xpath->evaluate('string(//f:Rozliczenie/f:SumaObciazen)'));
        self::assertSame('300.00', $xpath->evaluate('string(//f:Rozliczenie/f:SumaOdliczen)'));
        self::assertSame('167.00', $xpath->evaluate('string(//f:Rozliczenie/f:DoRozliczenia)'), '123.00 + 10.00 - 300.00 is an overpayment to return');
        self::assertSame('2 %', $xpath->evaluate('string(//f:Skonto/f:WysokoscSkonta)'));
        self::assertSame('U-1', $xpath->evaluate('string(//f:WarunkiTransakcji/f:Umowy/f:NrUmowy)'));
        self::assertSame('DAP', $xpath->evaluate('string(//f:WarunkiDostawy)'));
    }

    public function testAnOverpaymentIsShownAsAmountToReturn(): void
    {
        $invoice = Fixtures::builder()
            ->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23))
            ->additionalSettlement(new AdditionalSettlement([], [Adjustment::of('200.00', 'Prepayment')]))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('77.00', $xpath->evaluate('string(//f:Rozliczenie/f:DoRozliczenia)'), '123.00 - 200.00');
        self::assertSame('', $xpath->evaluate('string(//f:Rozliczenie/f:DoZaplaty)'));
    }

    public function testPartialPaymentsAreMarkedPartialOrComplete(): void
    {
        $partial = Fixtures::builder()->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23))
            ->payment(Payment::partlyPaid([new PartialPayment(Money::pln('50.00'), new DateTimeImmutable('2026-05-20'), PaymentMethod::Card)]))->build();
        $complete = Fixtures::builder()->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23))
            ->payment(Payment::partlyPaid([new PartialPayment(Money::pln('50.00'), new DateTimeImmutable('2026-05-20')), new PartialPayment(Money::pln('73.00'), new DateTimeImmutable('2026-05-25'))]))->build();

        self::assertSame('1', $this->xpath($partial)->evaluate('string(//f:ZnacznikZaplatyCzesciowej)'));
        self::assertSame('2', $this->xpath($complete)->evaluate('string(//f:ZnacznikZaplatyCzesciowej)'));
        self::assertSame('2', $this->xpath($partial)->evaluate('string(//f:ZaplataCzesciowa/f:FormaPlatnosci)'));
    }

    public function testAnAttachmentWithTablesPassesTheSchema(): void
    {
        $invoice = Fixtures::builder()
            ->addLine(InvoiceLine::of('Services', '1', 'szt.', '100.00', VatRate::Rate23))
            ->attachment(new Attachment([new AttachmentBlock(
                ['Period' => '2026-05'],
                'Statement',
                ['Hours worked per project.'],
                [new AttachmentTable(
                    [new AttachmentColumn('Project'), new AttachmentColumn('Hours', ColumnType::Decimal), new AttachmentColumn('Day', ColumnType::Date)],
                    [['Alpha', '7.5', '2026-05-04'], ['Beta', '2', '2026-05-05']],
                    'Hours',
                    ['Total', '9.5', ''],
                    ['Source' => 'timesheet'],
                )],
            )]))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('Statement', $xpath->evaluate('string(//f:Zalacznik/f:BlokDanych/f:ZNaglowek)'));
        self::assertSame('dec', $xpath->evaluate('string(//f:TNaglowek/f:Kol[2]/@Typ)'));
        self::assertSame(2.0, $xpath->evaluate('count(//f:Tabela/f:Wiersz)'));
        self::assertSame('9.5', $xpath->evaluate('string(//f:Suma/f:SKom[2])'));
    }

    public function testPartyExtrasAndTheStateBeforeACorrectionAreSerializedInOrder(): void
    {
        $seller = new Seller(Nip::of('5265877635'), 'Seller sp. z o.o.', new Address('PL', 'ul. Prosta 1', '00-001 Warszawa'), eori: 'PL5265877635000', vatPrefix: 'PL', correspondenceAddress: new Address('PL', 'Skrytka 5'));
        $buyer = new Buyer(BuyerIdentifier::nip(Nip::of('1111111111')), 'Gmina Przykład', new Address('PL', 'Rynek 1'), eori: 'PL1111111111000', buyerKey: 'B-1', localGovernmentSubunit: true, vatGroupMember: true);
        $oldBuyer = new Buyer(BuyerIdentifier::nip(Nip::of('1111111111')), 'Gmina Dawna', new Address('PL', 'Rynek 2'), buyerKey: 'B-1');
        $invoice = Fixtures::builder()
            ->seller($seller)->buyer($buyer)
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'FV/1')], sellerBefore: new Seller(Nip::of('5265877635'), 'Old Seller', new Address('PL', 'ul. Stara 1')), buyersBefore: [$oldBuyer]))
            ->addLine(InvoiceLine::of('Widget', '10', 'szt.', '10.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Widget', '8', 'szt.', '10.00', VatRate::Rate23))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('PL', $xpath->evaluate('string(//f:Podmiot1/f:PrefiksPodatnika)'));
        self::assertSame('PL5265877635000', $xpath->evaluate('string(//f:Podmiot1/f:NrEORI)'));
        self::assertSame('Skrytka 5', $xpath->evaluate('string(//f:Podmiot1/f:AdresKoresp/f:AdresL1)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Podmiot2/f:JST)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Podmiot2/f:GV)'));
        self::assertSame('B-1', $xpath->evaluate('string(//f:Podmiot2/f:IDNabywcy)'));
        self::assertSame('Old Seller', $xpath->evaluate('string(//f:Podmiot1K/f:DaneIdentyfikacyjne/f:Nazwa)'));
        self::assertSame('Gmina Dawna', $xpath->evaluate('string(//f:Podmiot2K/f:DaneIdentyfikacyjne/f:Nazwa)'));
        self::assertSame('B-1', $xpath->evaluate('string(//f:Podmiot2K/f:IDNabywcy)'));
    }

    public function testTransportAndContractualCurrencyPassTheSchema(): void
    {
        $transport = new Transport(
            type: TransportType::Road,
            cargo: CargoType::Pallet,
            carrier: new Carrier(BuyerIdentifier::nip(Nip::of('1111111111')), 'Fast Trans', new Address('PL', 'ul. Trasowa 1')),
            orderNumber: 'TR-1',
            packagingUnit: 'pallets',
            startsAt: new DateTimeImmutable('2026-06-02T08:00:00+00:00'),
            endsAt: new DateTimeImmutable('2026-06-02T18:00:00+00:00'),
            from: new Address('PL', 'Magazyn 1'),
            via: [new Address('DE', 'Berlin')],
            to: new Address('CZ', 'Praha'),
        );
        $invoice = Fixtures::builder()
            ->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23))
            ->terms(new TransactionTerms(contractualRate: Decimal::of('4.35'), contractualCurrency: 'EUR', transports: [$transport, new Transport(otherType: 'Drone', otherCargo: 'Mixed')], intermediary: true))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('3', $xpath->evaluate('string(//f:Transport[1]/f:RodzajTransportu)'));
        self::assertSame('13', $xpath->evaluate('string(//f:Transport[1]/f:OpisLadunku)'));
        self::assertSame('2026-06-02T08:00:00Z', $xpath->evaluate('string(//f:Transport[1]/f:DataGodzRozpTransportu)'));
        self::assertSame('Fast Trans', $xpath->evaluate('string(//f:Przewoznik/f:DaneIdentyfikacyjne/f:Nazwa)'));
        self::assertSame('Drone', $xpath->evaluate('string(//f:Transport[2]/f:OpisInnegoTransportu)'));
        self::assertSame('EUR', $xpath->evaluate('string(//f:WalutaUmowna)'));
        self::assertSame('1', $xpath->evaluate('string(//f:PodmiotPosredniczacy)'));
    }

    public function testNewMeansOfTransportPassTheSchema(): void
    {
        $car = NewMeansOfTransport::landVehicle(new DateTimeImmutable('2026-05-01'), 1, '120 km', vin: 'WVWZZZ1JZXW000001', brand: 'VW', model: 'Golf', color: 'red');
        $boat = NewMeansOfTransport::vessel(new DateTimeImmutable('2026-05-02'), 2, '10 h', hullNumber: 'H-1');
        $plane = NewMeansOfTransport::aircraft(new DateTimeImmutable('2026-05-03'), 3, '5 h');
        $invoice = Fixtures::builder()
            ->annotations(new Annotations(newTransport: new NewTransportSupply([$car, $boat, $plane], true)))
            ->addLine(InvoiceLine::of('Car', '1', 'szt.', '1000.00', VatRate::Rate23))
            ->addLine(InvoiceLine::of('Boat', '1', 'szt.', '1000.00', VatRate::Rate23))
            ->addLine(InvoiceLine::of('Plane', '1', 'szt.', '1000.00', VatRate::Rate23))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame('1', $xpath->evaluate('string(//f:NoweSrodkiTransportu/f:P_22)'));
        self::assertSame('1', $xpath->evaluate('string(//f:NoweSrodkiTransportu/f:P_42_5)'));
        self::assertSame('WVWZZZ1JZXW000001', $xpath->evaluate('string(//f:NowySrodekTransportu[1]/f:P_22B1)'));
        self::assertSame('H-1', $xpath->evaluate('string(//f:NowySrodekTransportu[2]/f:P_22C1)'));
        self::assertSame('5 h', $xpath->evaluate('string(//f:NowySrodekTransportu[3]/f:P_22D)'));
    }

    public function testBrokenExtrasAreReported(): void
    {
        try {
            Fixtures::builder()
                ->addLine(InvoiceLine::of('Widget', '1', 'szt.', '100.00', VatRate::Rate23)->withDiscount('500.00'))
                ->addInfo('k', 'v', 9)
                ->attachment(new Attachment([new AttachmentBlock(['k' => 'v'], null, [], [new AttachmentTable([new AttachmentColumn('A'), new AttachmentColumn('B')], [['only one']])])]))
                ->payment(new Payment(PaymentMethod::Cash, new DateTimeImmutable('2026-05-01'), partialPayments: [new PartialPayment(Money::pln('1.00'), new DateTimeImmutable('2026-05-01'))], skontoConditions: 'x'))
                ->build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $text = implode(' ', $e->violations);
            self::assertStringContainsString('refers to line 9', $text);
            self::assertStringContainsString('row 1 has 1 cells for 2 columns', $text);
            self::assertStringContainsString('not both', $text);
            self::assertStringContainsString('needs both its conditions and its amount', $text);
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
