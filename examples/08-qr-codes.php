<?php

declare(strict_types=1);

/**
 * 08 - QR codes for invoice printouts and PDFs.
 *
 *   php examples/08-qr-codes.php
 *
 * KOD I  (every invoice)       lets anyone check the invoice in KSeF.
 * KOD II (offline invoices)    proves the issuer's identity; signed with a KSeF *Offline* certificate.
 *
 * The SDK produces the links and the caption. Drawing the image is up to a QR library; `composer require
 * bacon/bacon-qr-code` is a good one (this example uses it automatically when it is installed).
 */

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Qr\VerificationLinks;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;
$policy = new PollingPolicy(timeoutSeconds: 120.0);
$links = new VerificationLinks($example->environment);

$invoice = $example->invoice()->addLine(InvoiceLine::of('Consulting', '1', 'h', '100.00', VatRate::Rate23))->build();
$document = InvoiceDocument::fromInvoice($invoice);          // the exact bytes you send are the ones the hash covers
$result = $ksef->waitForInvoice($ksef->sendInvoice($document), $policy, untilStored: true)->assertAccepted();

step('KOD I');
$url = $links->invoiceUrl($example->nip, $invoice->issueDate, $document);
say('  link:    ' . $url);
say('  caption: ' . $links->label($result->ksefNumber));      // the KSeF number; "OFFLINE" until one is known

step('KOD II (offline invoices)');
$offline = $ksef->requestCertificate('offline qr', CertificateType::Offline, policy: $policy);
$url2 = $links->certificateUrl(ContextIdentifier::nip($example->nip->value), $example->nip, $document->hash(), $offline->toOfflineCertificate());
say('  link: ' . $url2);
$ksef->revokeCertificate($offline->serialNumber);

step('Rendering the image');
if (class_exists(BaconQrCode\Writer::class)) {
    $renderer = new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(300), new BaconQrCode\Renderer\Image\SvgImageBackEnd());
    $svg = (new BaconQrCode\Writer($renderer))->writeString($url);
    file_put_contents(sys_get_temp_dir() . '/ksef-kod1.svg', $svg);
    say('  saved ' . sys_get_temp_dir() . '/ksef-kod1.svg');
} else {
    say('  (install bacon/bacon-qr-code to render the link as an SVG image)');
}
