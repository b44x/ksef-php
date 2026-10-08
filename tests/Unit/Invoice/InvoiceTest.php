<?php

declare(strict_types=1);

namespace Ksef\Tests\Unit\Invoice;

use DateTimeImmutable;
use Ksef\Exception\ValidationException;
use Ksef\Invoice\Annotations;
use Ksef\Invoice\Buyer;
use Ksef\Invoice\BuyerIdentifier;
use Ksef\Invoice\CorrectedInvoice;
use Ksef\Invoice\Correction;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceLine;
use Ksef\Invoice\Money;
use Ksef\Invoice\VatRate;
use Ksef\Support\Nip;
use Ksef\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testTotalsAreComputedPerRateBucketAndRounded(): void
    {
        $totals = Fixtures::standardInvoice()->totals();

        self::assertCount(2, $totals->buckets);
        self::assertSame('1500.00', $totals->buckets[0]->net->toString());
        self::assertSame('345.00', $totals->buckets[0]->vat?->toString());
        self::assertSame('149.97', $totals->buckets[1]->net->toString());
        self::assertSame('12.00', $totals->buckets[1]->vat?->toString(), '149.97 * 8% = 11.9976 → 12.00');
        self::assertSame('1649.97', $totals->net()->toString());
        self::assertSame('357.00', $totals->vat()->toString());
        self::assertSame('2006.97', $totals->gross()->toString());
    }

    public function testVatIsCalculatedOnTheSumNotPerLine(): void
    {
        $invoice = Fixtures::builder()
            ->addLine(InvoiceLine::of('A', '1', null, '0.10', VatRate::Rate23))
            ->addLine(InvoiceLine::of('B', '1', null, '0.10', VatRate::Rate23))
            ->addLine(InvoiceLine::of('C', '1', null, '0.10', VatRate::Rate23))
            ->build();

        // Per line: 3 * round(0.023) = 0.00; on the sum: round(0.069) = 0.07.
        self::assertSame('0.07', $invoice->totals()->vat()->toString());
    }

    public function testReportsEveryViolationAtOnce(): void
    {
        try {
            new Invoice(
                number: str_repeat('x', 300),
                issueDate: new DateTimeImmutable('1999-01-01'),
                currency: 'pln',
                seller: Fixtures::seller(),
                buyer: new Buyer(BuyerIdentifier::euVat('de', 'bad number'), ''),
                lines: [],
            );
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $text = implode("\n", $e->violations);
            self::assertStringContainsString('Invoice number is longer than 256', $text);
            self::assertStringContainsString('Issue date 1999-01-01', $text);
            self::assertStringContainsString('Currency "pln"', $text);
            self::assertStringContainsString('Buyer name must not be empty', $text);
            self::assertStringContainsString('EU VAT country prefix', $text);
            self::assertStringContainsString('between 1 and 10000 lines', $text);
            self::assertGreaterThanOrEqual(6, \count($e->violations));
        }
    }

    public function testExemptLinesRequireALegalBasis(): void
    {
        $builder = Fixtures::builder()->addLine(InvoiceLine::of('Training', '1', null, '100.00', VatRate::Exempt));

        try {
            $builder->build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('exemptionBasis', implode(' ', $e->violations));
        }

        $invoice = $builder->annotations(new Annotations(exemptionBasis: 'Art. 43 ust. 1 pkt 29 lit. b ustawy o VAT'))->build();
        self::assertNull($invoice->totals()->buckets[0]->vat);
    }

    public function testRatesSharingAHeaderFieldCannotBeMixed(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be mixed');
        Fixtures::builder()
            ->addLine(InvoiceLine::of('A', '1', null, '1.00', VatRate::Rate23))
            ->addLine(InvoiceLine::of('B', '1', null, '1.00', VatRate::Rate22))
            ->build();
    }

    public function testForeignCurrencyNeedsAnExchangeRateAndYieldsVatInPln(): void
    {
        $builder = Fixtures::builder()->currency('EUR')->addLine(InvoiceLine::of('Service', '1', null, '100.00', VatRate::Rate23, 'EUR'));

        try {
            $builder->build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('exchange rate', implode(' ', $e->violations));
        }

        $totals = $builder->exchangeRate('4.3210')->build()->totals();
        self::assertSame('23.00', $totals->buckets[0]->vat?->toString());
        self::assertSame('99.38', $totals->buckets[0]->vatPln?->toString(), '23.00 * 4.3210');
    }

    public function testLineCurrencyMustMatchTheInvoice(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('differs from the invoice currency');
        Fixtures::builder()->addLine(InvoiceLine::of('A', '1', null, '1.00', VatRate::Rate23, 'EUR'))->build();
    }

    public function testCorrectionTotalsAreDifferencesBetweenAfterAndBeforeLines(): void
    {
        $before = InvoiceLine::of('Widget', '10', 'pcs', '20.00', VatRate::Rate23)->asBefore();
        $after = InvoiceLine::of('Widget', '8', 'pcs', '20.00', VatRate::Rate23);

        $invoice = Fixtures::builder()
            ->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'FV/2026/05/009')]))
            ->addLine($before)
            ->addLine($after)
            ->build();

        self::assertSame('-40.00', $invoice->totals()->net()->toString());
        self::assertSame('-9.20', $invoice->totals()->vat()->toString());
        self::assertSame('-49.20', $invoice->totals()->gross()->toString());
    }

    public function testCorrectionRulesAreEnforced(): void
    {
        $line = InvoiceLine::of('Widget', '1', null, '1.00', VatRate::Rate23);

        try {
            Fixtures::builder()->correction(new Correction([new CorrectedInvoice(new DateTimeImmutable('2026-05-02'), 'X')]))->addLine($line)->build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('"before correction" line', implode(' ', $e->violations));
        }

        $this->expectException(ValidationException::class);
        Fixtures::builder()->addLine($line->asBefore())->build();
    }

    public function testNipChecksumIsVerifiedByDefault(): void
    {
        self::assertSame('5265877635', Nip::of('526-587-76-35')->value);
        self::assertSame('5265877635', Nip::of('PL 526 587 76 35')->value);
        self::assertSame('1234567890', Nip::unchecked('1234567890')->value);

        $this->expectException(ValidationException::class);
        Nip::of('1234567890');
    }

    public function testMoneyRejectsMalformedCurrenciesAndFloats(): void
    {
        self::assertSame('10.50 EUR', (string) Money::of('10.50', 'EUR'));

        $this->expectException(ValidationException::class);
        Money::of('10.50', 'euro');
    }

    public function testForbiddenCharactersAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('characters that KSeF rejects');
        Fixtures::builder()->addLine(InvoiceLine::of("Bad\x07name", '1', null, '1.00', VatRate::Rate23))->build();
    }
}
