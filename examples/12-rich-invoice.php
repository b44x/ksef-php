<?php

declare(strict_types=1);

/**
 * 12 - An invoice with the optional extras: line discounts, remarks, partial payments, charges and deductions,
 * transaction terms, a structured attachment, an additional party and an authorised entity.
 *
 *   php examples/12-rich-invoice.php
 *
 * None of this is needed for a plain invoice (see example 01). Reach for it when your business case asks for it.
 */

use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\AdditionalSettlement;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Adjustment;
use B4x\Ksef\Invoice\Attachment;
use B4x\Ksef\Invoice\AttachmentBlock;
use B4x\Ksef\Invoice\AttachmentColumn;
use B4x\Ksef\Invoice\AttachmentTable;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\ColumnType;
use B4x\Ksef\Invoice\DocumentReference;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\PartialPayment;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\ThirdParty;
use B4x\Ksef\Invoice\ThirdPartyRole;
use B4x\Ksef\Invoice\TransactionTerms;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Testing\TestEnvironment;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;

if ($example->environment === Environment::Test) {
    // Real taxpayers notify the Ministry of Finance once; on TEST this is a single call.
    TestEnvironment::allowAttachments($example->nip, $example->http, $example->factory, $example->factory);
} else {
    say('Attachments need your prior consent in KSeF: ' . ($ksef->attachmentStatus()->allowed ? 'given' : 'NOT given'));
}

$invoice = $example->invoice()
    ->saleDate(new DateTimeImmutable('today'))
    ->addLine(InvoiceLine::of('Consulting', '20', 'h', '150.00', VatRate::Rate23)->withDiscount('100.00'))   // a discount for the whole line
    ->addLine(InvoiceLine::of('Travel expenses', '1', 'szt.', '80.00', VatRate::Rate23))
    ->addInfo('Project', 'Alpha')                                  // free remark on the invoice
    ->addInfo('Cost centre', 'CC-17', 2)                           // ... or tied to one line
    ->addWarehouseDocument('WZ/2026/118')
    ->addThirdParty(ThirdParty::of(ThirdPartyRole::Recipient, BuyerIdentifier::nip(Nip::of('5265877635')), 'Branch office', new Address('PL', 'ul. Filialna 3', '00-003 Miasto')))
    ->terms(new TransactionTerms(contracts: [new DocumentReference('U-2026/7', new DateTimeImmutable('2026-01-15'))], orders: [new DocumentReference('ZAM-55')]))
    ->payment(Payment::partlyPaid(
        [new PartialPayment(Money::pln('500.00'), new DateTimeImmutable('today'), PaymentMethod::BankTransfer)],
        dueDate: new DateTimeImmutable('+14 days'),
        bankAccounts: ['PL61109010140000071219812874'],
    ))
    ->additionalSettlement(new AdditionalSettlement(charges: [Adjustment::of('25.00', 'Packaging deposit')]))
    ->attachment(new Attachment([new AttachmentBlock(
        header: 'Hours worked',
        metadata: ['Period' => date('Y-m')],
        paragraphs: ['Statement of the consulting hours behind line 1.'],
        tables: [new AttachmentTable(
            [new AttachmentColumn('Day', ColumnType::Date), new AttachmentColumn('Hours', ColumnType::Decimal), new AttachmentColumn('Topic')],
            [[date('Y-m-01'), '8', 'Workshop'], [date('Y-m-02'), '12', 'Implementation']],
            totals: ['Total', '20', ''],
        )],
    )]))
    ->build();

say(sprintf('total %s, line 1 net %s after the discount, in the P_15 field %s', $invoice->totals()->gross(), $invoice->lines[0]->netAmount(), $invoice->amountDue()));

// Invoices with an attachment are accepted by KSeF in batch sessions only; a batch of one is fine.
$policy = new PollingPolicy(timeoutSeconds: 180.0);
$batch = $ksef->sendBatch([$invoice]);
$status = $ksef->waitForSession($batch->sessionReference, $policy);
say(sprintf('batch %s finished: %d accepted, %d failed', $batch->sessionReference, $status->successfulInvoiceCount ?? 0, $status->failedInvoiceCount ?? 0));
foreach ($ksef->listSessionInvoices($batch->sessionReference)->invoices as $result) {
    say('  ' . ($result->ksefNumber ?? 'rejected: ' . $result->status->description));
}
