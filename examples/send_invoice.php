<?php

declare(strict_types=1);

use B4x\Ksef\Exception\InvoiceRejectedException;
use B4x\Ksef\Exception\SubmissionOutcomeUnknownException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Payment;
use B4x\Ksef\Invoice\PaymentMethod;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Support\Nip;

require __DIR__ . '/bootstrap.php';

$ksef = createClient();
$sellerNip = (string) getenv('KSEF_NIP');

try {
    $invoice = Invoice::builder()
        ->number('FV/' . date('Y/m') . '/' . random_int(1000, 9999))
        ->issueDate(new DateTimeImmutable('today'))
        ->saleDate(new DateTimeImmutable('today'))
        ->seller(new Seller(Nip::of($sellerNip), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1', '00-001 Warszawa'), 'billing@example.com'))
        ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('1234563218')), 'Sample Buyer S.A.', Address::poland('ul. Długa 5', '80-001 Gdańsk')))
        ->addLine(InvoiceLine::of('Consulting', '10', 'h', '150.00', VatRate::Rate23))
        ->addLine(InvoiceLine::of('Printed manual', '3', 'szt.', '49.99', VatRate::Rate8))
        ->payment(Payment::dueOn(new DateTimeImmutable('+14 days'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
        ->build();

    $submission = $ksef->sendInvoice($invoice);
    echo "Accepted for processing: {$submission->invoiceReference}\n";

    $result = $ksef->waitForInvoice($submission, new PollingPolicy(timeoutSeconds: 120.0))->assertAccepted();
    echo "KSeF number: {$result->ksefNumber}\n";

    $upo = $ksef->invoiceUpo($submission);
    file_put_contents(sys_get_temp_dir() . '/upo-' . $submission->invoiceReference . '.xml', $upo->xml);
    echo 'UPO saved (hash verified: ' . ($upo->verifyHash() ? 'yes' : 'no') . ")\n";
} catch (ValidationException $e) {
    // Nothing was sent. Every problem is listed.
    foreach ($e->violations as $violation) {
        fwrite(STDERR, "Invalid: {$violation}\n");
    }
    exit(1);
} catch (InvoiceRejectedException $e) {
    // KSeF processed and refused the document. Status 440 means it is already stored.
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} catch (SubmissionOutcomeUnknownException $e) {
    // The network failed mid-submission. Persist these two values and run recover_unknown_outcome.php.
    fwrite(STDERR, "Unknown outcome. session={$e->sessionReference} hash={$e->invoiceHash}\n");
    exit(3);
}
