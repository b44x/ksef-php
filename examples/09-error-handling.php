<?php

declare(strict_types=1);

/**
 * 09 - A tour of the failures you will meet, and what to do about each.
 *
 *   php examples/09-error-handling.php
 *
 * Everything the SDK throws extends B4x\Ksef\Exception\KsefException, so one catch-all is always possible.
 * The more specific types tell you what to do next.
 */

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\InvoiceNotAvailableException;
use B4x\Ksef\Exception\InvoiceRejectedException;
use B4x\Ksef\Exception\KsefException;
use B4x\Ksef\Exception\PollingTimeoutException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;

step('1. Local validation: nothing is sent, every problem is reported');
try {
    $example->invoice()->addLine(InvoiceLine::of('', '0', 'szt.', '-5.00', VatRate::Exempt))->build();
} catch (ValidationException $e) {
    foreach ($e->violations as $violation) {
        say('  - ' . $violation);
    }
    say('  -> fix the data and build again.');
}

step('2. KSeF refuses the document after processing (duplicate invoice number)');
$invoice = $example->invoice()->addLine(InvoiceLine::of('Consulting', '1', 'h', '100.00', VatRate::Rate23))->build();
$ksef->waitForInvoice($ksef->sendInvoice($invoice), new PollingPolicy(timeoutSeconds: 120.0), untilStored: true)->assertAccepted();
try {
    $status = $ksef->waitForInvoice($ksef->sendInvoice($invoice), new PollingPolicy(timeoutSeconds: 120.0))->assertAccepted();   // same number again
} catch (InvoiceRejectedException $e) {
    say('  ' . $e->getMessage());
    say('  -> status 440 means the invoice is already in KSeF: use assertStored() if that is the outcome you want.');
}

step('3. An API error: the request itself was refused');
try {
    $ksef->downloadInvoice('1234567890-20250101-ABCDEF123456-AB');                     // not a valid KSeF number (checksum)
} catch (ValidationException $e) {
    say('  malformed KSeF number: ' . $e->getMessage());
}
try {
    $ksef->downloadInvoice('5265877635-20250826-0100001AF629-AF');                    // well-formed, but not yours / not stored
} catch (InvoiceNotAvailableException) {
    say('  not stored yet: wait and retry.');
} catch (ApiException $e) {
    // AuthorizationException (403) is a subclass: the credentials lack a permission, see example 07.
    say(sprintf('  HTTP %d, KSeF code %s, trace id %s', $e->httpStatus, $e->ksefCode() ?? '-', $e->traceId ?? '-'));
    say('  -> quote the trace id when contacting KSeF support. ' . $e->getMessage());
}

step('4. Waiting too long is not failing');
try {
    $status = $ksef->waitForInvoice($ksef->sendInvoice($example->invoice()->addLine(InvoiceLine::of('Another', '1', 'h', '1.00', VatRate::Rate23))->build()), new PollingPolicy(0.0, 0.0, 1.0, 1.0, 1));
    say('  KSeF was fast enough this time (one attempt): ' . ($status->ksefNumber ?? 'processed'));
} catch (PollingTimeoutException $e) {
    say('  ' . $e->getMessage());
    say('  -> KSeF is still working. Keep the submission references and ask again later.');
}

step('The catch-all');
try {
    $ksef->downloadInvoice('not-a-number');
} catch (KsefException $e) {
    say('  any SDK failure: ' . $e::class);
}
