<?php

declare(strict_types=1);

use Ksef\Api\InvoiceDateType;
use Ksef\Api\InvoiceSubjectType;
use Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$ksef = createClient();

// Purchase invoices stored during the last 7 days. Use PermanentStorage dates for incremental sync.
$from = new DateTimeImmutable('-7 days');
$offset = 0;

do {
    $page = $ksef->searchInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, null, $offset, 100);

    foreach ($page->invoices as $metadata) {
        printf("%s  %s  %s %s\n", $metadata->ksefNumber, $metadata->invoiceNumber, $metadata->grossAmount->toString(2), $metadata->currency);

        $invoice = $ksef->downloadInvoice($metadata->ksefNumber, new PollingPolicy(timeoutSeconds: 30.0));
        file_put_contents(sys_get_temp_dir() . '/' . $metadata->ksefNumber . '.xml', $invoice->xml);
    }

    if ($page->hasMore && $page->isTruncated) {
        // The 10,000 record cap was reached: continue from the last record's date with the offset reset.
        $invoices = $page->invoices;
        $last = end($invoices);
        $from = $last !== false ? $last->permanentStorageDate : $from;
        $offset = 0;
    } else {
        $offset += 100;
    }
} while ($page->hasMore);
