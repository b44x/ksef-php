<?php

declare(strict_types=1);

/**
 * 03 - Many invoices at once.
 *
 *   php examples/03-batch.php
 *
 * Two options:
 *   - an online SESSION: several invoices, each with its own immediate acknowledgement (up to 12 hours open);
 *   - a BATCH: one ZIP, encrypted and uploaded in parts, processed asynchronously (best for thousands). Needs ext-zip.
 */

use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;
$policy = new PollingPolicy(timeoutSeconds: 180.0);

step('A. One online session, five invoices');
$session = $ksef->openOnlineSession();
$submissions = [];
foreach (range(1, 5) as $i) {
    $submissions[] = $session->send($example->invoice('SES')->addLine(InvoiceLine::of('Subscription ' . $i, '1', 'szt.', '99.00', VatRate::Rate23))->build());
}
$session->close();                               // closing starts the aggregate UPO
foreach ($submissions as $submission) {
    $result = $session->waitForInvoice($submission, $policy);
    say(sprintf('  %s -> %s', $submission->invoiceReference, $result->ksefNumber ?? 'rejected (' . $result->status->code . ')'));
}
$final = $session->waitUntilFinished($policy);
say(sprintf('  session: %d accepted, %d failed, %d UPO page(s)', $final->successfulInvoiceCount ?? 0, $final->failedInvoiceCount ?? 0, count($final->upoPages)));

step('B. A batch of eight invoices');
$invoices = [];
foreach (range(1, 8) as $i) {
    $invoices[] = $example->invoice('BATCH')->addLine(InvoiceLine::of('Item ' . $i, '1', 'szt.', '10.00', VatRate::Rate23))->build();
}
$batch = $ksef->sendBatch($invoices);            // zips, splits (<= 100 MB per part), encrypts, uploads, closes
say('  batch session: ' . $batch->sessionReference);

$status = $ksef->waitForSession($batch->sessionReference, $policy);
say(sprintf('  result: %s (%d accepted, %d failed)', $status->description, $status->successfulInvoiceCount ?? 0, $status->failedInvoiceCount ?? 0));

// Map KSeF's per-invoice results back to your own documents with the hashes returned by sendBatch().
foreach ($ksef->listSessionInvoices($batch->sessionReference)->invoices as $row) {
    $position = array_search($row->invoiceHash, $batch->invoiceHashes, true);
    say(sprintf('  your document #%s -> %s', $position === false ? '?' : (string) ($position + 1), $row->ksefNumber ?? 'rejected'));
}
