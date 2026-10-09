<?php

declare(strict_types=1);

/**
 * 10 - Offline invoicing: issue an invoice without KSeF, hand the buyer a printout with two QR codes, deliver
 * the XML to KSeF later (and what to do when KSeF rejects it for a technical reason).
 *
 *   php examples/10-offline-invoicing.php
 *
 * Offline modes exist so that business does not stop when KSeF is unavailable (or by choice, "offline24": deliver
 * by the next business day). You need a KSeF certificate of type OFFLINE for the seller - request it while KSeF is
 * reachable, keep it ready. See docs/OFFLINE.md.
 */

use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Exception\InvoiceRejectedException;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;
$policy = new PollingPolicy(timeoutSeconds: 120.0);

step('0. Prepare (while KSeF is reachable): an OFFLINE certificate, kept at hand');
$issued = $ksef->requestCertificate('offline invoicing', CertificateType::Offline, policy: $policy);
$certificate = $issued->toOfflineCertificate();
say('  certificate ' . $issued->serialNumber);

step('1. Issue the invoice - no network needed from here on');
$invoice = $example->invoice()->addLine(InvoiceLine::of('Consulting', '3', 'h', '150.00', VatRate::Rate23))->build();
$offline = $ksef->issueOfflineInvoice($invoice, $certificate);
say('  invoice  ' . $invoice->number . ' (' . $offline->document->size() . ' bytes of XML, store it!)');
say('  KOD I    ' . $offline->verificationUrl);
say('  KOD II   ' . $offline->issuerUrl);
say('  caption  ' . $offline->caption . '   <- printed under KOD I until a KSeF number exists');

step('2. Deliver it to KSeF later (offlineMode: true)');
$submission = $ksef->sendOfflineInvoice($offline);
$result = $ksef->waitForInvoice($submission, $policy, untilStored: true)->assertAccepted();
say('  KSeF number ' . $result->ksefNumber);

step('3. KSeF rejected an offline invoice for a technical reason? Send a technical correction');
// To show a rejection we deliver the same invoice twice; KSeF refuses the second one as a duplicate.
$duplicate = $ksef->sendOfflineInvoice($offline);
try {
    $ksef->waitForInvoice($duplicate, $policy)->assertAccepted();
} catch (InvoiceRejectedException $e) {
    say('  rejected as expected (KSeF: duplicate)');
}
// A real technical rejection (for example a schema error in your export) is repaired like this: build the invoice
// again with the same business content but valid, and link it to the rejected one by its hash. KOD I printed on the
// original then leads to the corrected invoice. It must be sent in an interactive session (the SDK does that).
//
//     $ksef->sendTechnicalCorrection($fixedInvoice, $rejectedOfflineInvoice);
//
// KSeF only accepts it for invoices that were really rejected: a duplicate of an accepted invoice is refused
// (error 21167), which is why this example does not run the call.

$ksef->revokeCertificate($issued->serialNumber);
say("\nDone.");
