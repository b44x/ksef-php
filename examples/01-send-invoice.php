<?php

declare(strict_types=1);

/**
 * 01 - The core flow: build an invoice, send it, wait for the verdict, fetch the UPO.
 *
 *   php examples/01-send-invoice.php
 *
 * What to remember: sending only means "KSeF accepted the document for processing". The verdict (KSeF number
 * or rejection) comes later, so you wait for it. That is why there are two calls.
 */

use B4x\Ksef\Exception\InvoiceRejectedException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;

step('1. Build the invoice (validated immediately, VAT is calculated for you)');
try {
    $invoice = $example->invoice()
        ->addLine(InvoiceLine::of('Consulting', '10', 'h', '150.00', VatRate::Rate23))
        ->addLine(InvoiceLine::of('Printed manual', '3', 'szt.', '49.99', VatRate::Rate8))
        ->payment(Payment::dueOn(new DateTimeImmutable('+14 days'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
        ->build();
} catch (ValidationException $e) {
    // Nothing was sent. Every problem is listed at once.
    foreach ($e->violations as $violation) {
        say('  - ' . $violation);
    }
    exit(1);
}
say(sprintf('  %s: net %s, VAT %s, gross %s PLN', $invoice->number, $invoice->totals()->net(), $invoice->totals()->vat(), $invoice->totals()->gross()));

step('2. Send it (accepted for processing - NOT yet approved)');
$submission = $ksef->sendInvoice($invoice);
say('  invoice reference: ' . $submission->invoiceReference);
say('  Persist $submission->sessionReference, ->invoiceReference and ->invoiceHash: they let you check the result later, even from another process.');

step('3. Wait for the verdict');
try {
    // untilStored: also wait until the invoice can be downloaded.
    $result = $ksef->waitForInvoice($submission, new PollingPolicy(timeoutSeconds: 120.0), untilStored: true)->assertAccepted();
} catch (InvoiceRejectedException $e) {
    // KSeF processed the document and refused it. The message carries KSeF's explanation.
    say('  Rejected: ' . $e->getMessage());
    exit(2);
}
say('  KSeF number: ' . $result->ksefNumber);

step('4. Fetch the UPO (official confirmation of receipt) and the stored invoice');
$upo = $ksef->invoiceUpo($submission);
say(sprintf('  UPO: %d bytes, hash verified: %s', strlen($upo->xml), $upo->verifyHash() ? 'yes' : 'NO'));
$stored = $ksef->downloadInvoice((string) $result->ksefNumber);
say(sprintf('  Downloaded invoice: %d bytes, hash verified: %s', strlen($stored->xml), $stored->verifyHash() ? 'yes' : 'NO'));

file_put_contents(sys_get_temp_dir() . '/ksef-upo.xml', $upo->xml);
say("\nDone. The UPO was saved to " . sys_get_temp_dir() . '/ksef-upo.xml');
