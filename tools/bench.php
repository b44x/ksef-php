<?php

declare(strict_types=1);

/**
 * Micro benchmarks for the CPU and memory bound parts of the SDK. No network, no dependencies.
 *
 *   php tools/bench.php [iterations]
 *
 * Numbers depend on the machine; compare runs on the same one.
 */

use B4x\Ksef\Batch\BatchPackager;
use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Support\Nip;

require __DIR__ . '/../vendor/autoload.php';

$iterations = max(1, (int) ($argv[1] ?? 5));

$seller = new Seller(Nip::of('5265877635'), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1/2', '00-001 Warszawa'));
$buyer = new Buyer(BuyerIdentifier::nip(Nip::of('1234563218')), 'Sample Buyer S.A.', Address::poland('ul. Długa 5', '80-001 Gdańsk'));

$invoice = static function (int $lines, int $number) use ($seller, $buyer): Invoice {
    $builder = Invoice::builder()->number('FV/' . $number)->issueDate('2026-06-01')->saleDate('2026-05-31')->seller($seller)->buyer($buyer);
    for ($i = 1; $i <= $lines; ++$i) {
        $builder->addLine(InvoiceLine::of('Item ' . $i, '2', 'pcs', '19.99', $i % 2 === 0 ? VatRate::Rate23 : VatRate::Rate8));
    }

    return $builder->build();
};

/** @return array{float, int} best time in milliseconds and peak memory growth in KiB */
$measure = static function (callable $work) use ($iterations): array {
    $best = INF;
    memory_reset_peak_usage();
    $before = memory_get_usage();
    for ($i = 0; $i < $iterations; ++$i) {
        $start = hrtime(true);
        $work();
        $best = min($best, (hrtime(true) - $start) / 1e6);
    }

    return [$best, (int) ((memory_get_peak_usage() - $before) / 1024)];
};

$rows = [];
$row = static function (string $label, callable $work) use (&$rows, $measure): void {
    [$ms, $kib] = $measure($work);
    $rows[] = [$label, $ms, $kib];
    fprintf(STDERR, "done: %s\n", $label);
};

foreach ([1, 100, 1000] as $lines) {
    $row(sprintf('build + validate invoice, %d line(s)', $lines), static fn() => $invoice($lines, 1));
    $built = $invoice($lines, 1);
    $row(sprintf('serialize + XSD validate, %d line(s)', $lines), static fn() => InvoiceDocument::fromInvoice($built));
}

$document = InvoiceDocument::fromInvoice($invoice(10, 1));
$row('SHA-256 of one invoice (' . $document->size() . ' B)', static fn() => Digest::sha256Base64($document->xml));

$payload = random_bytes(10 * 1024 * 1024);
$encryption = SessionEncryption::generate();
$row('AES-256-CBC encrypt 10 MiB', static fn() => $encryption->encrypt($payload));
$cipher = $encryption->encrypt($payload);
$row('AES-256-CBC decrypt 10 MiB', static fn() => $encryption->decrypt($cipher));

$documents = static function (int $count) use ($invoice): Generator {
    for ($i = 1; $i <= $count; ++$i) {
        yield InvoiceDocument::fromInvoice($invoice(5, $i));
    }
};
foreach ([100, 1000] as $count) {
    $row(sprintf('batch: serialize + zip %d invoices (streamed)', $count), static function () use ($documents, $count): void {
        $package = (new BatchPackager())->package($documents($count));
        $package->dispose();
    });
}

printf("%-52s %12s %14s\n", 'Scenario (best of ' . $iterations . ')', 'time [ms]', 'peak [KiB]');
foreach ($rows as [$label, $ms, $kib]) {
    printf("%-52s %12.2f %14d\n", $label, $ms, $kib);
}
