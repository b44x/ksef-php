<?php

declare(strict_types=1);

use Ksef\Invoice\Address;
use Ksef\Invoice\Buyer;
use Ksef\Invoice\BuyerIdentifier;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceLine;
use Ksef\Invoice\Seller;
use Ksef\Invoice\VatRate;
use Ksef\Status\InvoiceSubmission;
use Ksef\Support\Nip;

require __DIR__ . '/bootstrap.php';

$ksef = createClient();
$seller = new Seller(Nip::of((string) getenv('KSEF_NIP')), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1', '00-001 Warszawa'));
$buyer = new Buyer(BuyerIdentifier::nip(Nip::of('1234563218')), 'Sample Buyer S.A.');

// One session, many invoices: cheaper than a session per invoice.
$session = $ksef->openOnlineSession();

/** @var list<InvoiceSubmission> $submissions */
$submissions = [];
foreach (range(1, 5) as $i) {
    $invoice = Invoice::builder()
        ->number(sprintf('BATCH/%s/%03d', date('Ymd-His'), $i))
        ->issueDate(new DateTimeImmutable('today'))
        ->seller($seller)
        ->buyer($buyer)
        ->addLine(InvoiceLine::of('Subscription', '1', 'szt.', '99.00', VatRate::Rate23))
        ->build();

    $submissions[] = $session->send($invoice);
}

$session->close();                      // KSeF now generates the aggregate UPO
$final = $session->waitUntilFinished();

printf(
    "Session %s: %d invoices, %d accepted, %d failed\n",
    $session->referenceNumber,
    $final->invoiceCount ?? 0,
    $final->successfulInvoiceCount ?? 0,
    $final->failedInvoiceCount ?? 0,
);

foreach ($submissions as $submission) {
    $result = $session->waitForInvoice($submission);
    printf("%s -> %s (%d)\n", $submission->invoiceReference, $result->ksefNumber ?? 'rejected', $result->status->code);
}

foreach ($final->upoPages as $page) {
    $upo = $ksef->sessionUpo($session->referenceNumber, $page->referenceNumber);
    echo "Aggregate UPO page {$page->referenceNumber}: " . strlen($upo->xml) . " bytes\n";
}
