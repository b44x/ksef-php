<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Support;

use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceBuilder;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Support\Nip;

/** Reusable sample domain objects. All identifiers are fictitious. */
final class Fixtures
{
    public const SELLER_NIP = '5265877635';
    public const BUYER_NIP = '1234563218';

    public static function seller(): Seller
    {
        return new Seller(Nip::of(self::SELLER_NIP), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1/2', '00-001 Warszawa'), 'billing@seller.example', '+48500100200');
    }

    public static function buyer(): Buyer
    {
        return new Buyer(BuyerIdentifier::nip(Nip::of(self::BUYER_NIP)), 'Sample Buyer S.A.', Address::poland('ul. Długa 5', '80-001 Gdańsk'));
    }

    public static function builder(): InvoiceBuilder
    {
        return Invoice::builder()
            ->number('FV/2026/06/001')
            ->issueDate('2026-06-01')
            ->saleDate('2026-05-31')
            ->seller(self::seller())
            ->buyer(self::buyer());
    }

    public static function standardInvoice(): Invoice
    {
        return self::builder()
            ->addLine(InvoiceLine::of('Consulting & "support" <June>', '10', 'h', '150.00', VatRate::Rate23))
            ->addLine(InvoiceLine::of('Printed manual', '3', 'pcs', '49.99', VatRate::Rate8))
            ->build();
    }
}
