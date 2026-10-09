<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Invoice;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\ThirdParty;
use B4x\Ksef\Invoice\ThirdPartyRole;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\MutableClock;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ThirdPartyTest extends TestCase
{
    public function testStandardAndFreeTextRolesAreSerializedAfterTheBuyerAndPassTheSchema(): void
    {
        $invoice = $this->base()
            ->addThirdParty(ThirdParty::of(
                ThirdPartyRole::Recipient,
                BuyerIdentifier::nip(Nip::of('5265877635')),
                'Branch office',
                new Address('PL', 'ul. Prosta 1, 00-001 Warszawa'),
                email: 'branch@example.com',
                share: Decimal::of('25.5'),
                customerNumber: 'C-7',
                eori: 'PL123456789',
            ))
            ->addThirdParty(ThirdParty::withOtherRole('Logistics partner', BuyerIdentifier::euVat('DE', '123456789'), 'Spedition GmbH'))
            ->build();

        $xpath = $this->xpath($invoice);

        self::assertSame(2.0, $xpath->evaluate('count(//f:Podmiot3)'));
        self::assertSame(1.0, $xpath->evaluate('count(//f:Podmiot2/following-sibling::f:Podmiot3[1])'));
        self::assertSame('2', $xpath->evaluate('string(//f:Podmiot3[1]/f:Rola)'));
        self::assertSame('5265877635', $xpath->evaluate('string(//f:Podmiot3[1]/f:DaneIdentyfikacyjne/f:NIP)'));
        self::assertSame('25.5', $xpath->evaluate('string(//f:Podmiot3[1]/f:Udzial)'));
        self::assertSame('PL123456789', $xpath->evaluate('string(//f:Podmiot3[1]/f:NrEORI)'));
        self::assertSame('1', $xpath->evaluate('string(//f:Podmiot3[2]/f:RolaInna)'));
        self::assertSame('Logistics partner', $xpath->evaluate('string(//f:Podmiot3[2]/f:OpisRoli)'));
        self::assertSame('DE', $xpath->evaluate('string(//f:Podmiot3[2]/f:DaneIdentyfikacyjne/f:KodUE)'));
    }

    public function testInvalidThirdPartyDataIsReported(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('the share must be between 0 and 100 percent');

        $this->base()
            ->addThirdParty(ThirdParty::of(ThirdPartyRole::Payer, BuyerIdentifier::none(), 'Someone', share: Decimal::of('101')))
            ->build();
    }

    private function base(): \B4x\Ksef\Invoice\InvoiceBuilder
    {
        return Fixtures::builder()->addLine(InvoiceLine::of('Service', '1', 'szt.', '100.00', VatRate::Rate23));
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
