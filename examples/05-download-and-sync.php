<?php

declare(strict_types=1);

/**
 * 05 - Reading invoices back: search metadata, download one, and keep a local copy in sync.
 *
 *   php examples/05-download-and-sync.php
 *
 * For synchronising purchase invoices (or your own sales) use ONE cursor: the permanent storage date.
 * Export, store the cursor, and next time continue from it.
 */

use B4x\Ksef\Api\InvoiceDateType;
use B4x\Ksef\Api\InvoiceSubjectType;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;
$policy = new PollingPolicy(timeoutSeconds: 120.0);

if ($example->credentials !== null) {
    step('Preparing: the throw-away taxpayer issues two invoices so there is something to read');
    foreach ([1, 2] as $i) {
        $result = $ksef->waitForInvoice($ksef->sendInvoice($example->invoice()->addLine(InvoiceLine::of('Item ' . $i, '1', 'szt.', '10.00', VatRate::Rate23))->build()), $policy, untilStored: true);
        say('  ' . $result->ksefNumber);
    }
}

step('Search sales invoices (metadata only, paged)');
$from = new DateTimeImmutable('-1 day');
$page = $ksef->searchInvoices(InvoiceSubjectType::Seller, InvoiceDateType::PermanentStorage, $from);
foreach ($page->invoices as $invoice) {
    say(sprintf('  %s  %-24s gross %s %s', $invoice->ksefNumber, $invoice->invoiceNumber, $invoice->grossAmount->toString(2), $invoice->currency));
}
say($page->hasMore ? '  (more pages: request the next pageOffset)' : '  (that was everything)');

step('Download one invoice (its hash is verified)');
$first = $page->invoices[0] ?? null;
if ($first !== null) {
    $document = $ksef->downloadInvoice($first->ksefNumber);
    say(sprintf('  %s: %d bytes, hash ok: %s', $document->ksefNumber, strlen($document->xml), $document->verifyHash() ? 'yes' : 'NO'));
}

step('Incremental synchronisation with an export (ZIP of {ksefNumber}.xml + _metadata.json)');
$cursorFile = sys_get_temp_dir() . '/ksef-sync-cursor.txt';
$cursor = is_file($cursorFile) ? new DateTimeImmutable((string) file_get_contents($cursorFile)) : $from;
$zip = sys_get_temp_dir() . '/ksef-export.zip';

$package = $ksef->exportInvoices(InvoiceSubjectType::Seller, InvoiceDateType::PermanentStorage, $cursor, null, $zip, $policy);
say(sprintf('  %d invoice(s) exported to %s', $package->invoiceCount, $zip));

// Next cursor: where this export stopped (if it was cut at the 10,000 invoice / 1 GB limit), else the safe high-water mark.
$next = $package->isTruncated ? $package->continueFrom : $package->permanentStorageHwmDate;
if ($next !== null) {
    file_put_contents($cursorFile, $next->format(DATE_ATOM));
    say('  cursor saved: ' . $next->format(DATE_ATOM) . ($package->isTruncated ? ' (export was truncated: run again right away)' : ''));
}
