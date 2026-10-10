<?php

declare(strict_types=1);

/**
 * 02 - Beyond the standard invoice: advance (ZAL), final/settlement (ROZ), simplified (UPR) and corrections (KOR, KOR_ZAL, KOR_ROZ).
 *
 *   php examples/02-invoice-kinds.php
 */

use B4x\Ksef\Invoice\AdvanceInvoiceReference;
use B4x\Ksef\Invoice\AdvancePayment;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\CorrectionType;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\Settlement;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;
$policy = new PollingPolicy(timeoutSeconds: 120.0);

/** Sends an invoice and returns the KSeF number, or stops with KSeF's explanation. */
$send = static function (string $label, Invoice $invoice) use ($ksef, $policy): string {
    $result = $ksef->waitForInvoice($ksef->sendInvoice($invoice), $policy, untilStored: true)->assertAccepted();
    say(sprintf('  %-10s %s -> %s', $label, $invoice->number, $result->ksefNumber));

    return (string) $result->ksefNumber;
};

step('Simplified invoice (UPR): at most PLN 450, buyer identified by NIP');
$send('UPR', $example->invoice('UPR')->simplified()->addLine(InvoiceLine::of('Coffee', '2', 'szt.', '10.00', VatRate::Rate23))->build());

step('Advance invoice (ZAL): the customer paid 1230.00 PLN gross up front for a 5000.00 net order');
$advanceInvoice = $example->invoice('ZAL')
    ->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, new DateTimeImmutable('today')))
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))   // lines of the ORDER
    ->build();
$advanceKsef = $send('ZAL', $advanceInvoice);

step('Final invoice (ROZ): delivery done; the amount due is the total minus the advance');
$final = $example->invoice('ROZ')
    ->saleDate(new DateTimeImmutable('today'))
    ->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceKsef)], Money::pln('1230.00')))
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))
    ->build();
say(sprintf('  total %s, advances 1230.00, still due %s', $final->totals()->gross(), $final->amountDue()));
$finalKsef = $send('ROZ', $final);

step('Correction (KOR): the customer returned 2 of 10 pieces');
$original = $example->invoice('FV')->addLine(InvoiceLine::of('Widget', '10', 'szt.', '20.00', VatRate::Rate23))->build();
$originalKsef = $send('FV', $original);
$correction = $example->invoice('KOR')
    ->correction(new Correction([new CorrectedInvoice($original->issueDate, $original->number, $originalKsef)], CorrectionType::CorrectionInvoicePeriod, 'Goods returned'))
    ->addLine(InvoiceLine::of('Widget', '10', 'szt.', '20.00', VatRate::Rate23)->asBefore())   // the line as it WAS
    ->addLine(InvoiceLine::of('Widget', '8', 'szt.', '20.00', VatRate::Rate23))                // the line as it IS now
    ->build();
say(sprintf('  correction changes the total by %s PLN', $correction->totals()->gross()));
$send('KOR', $correction);

step('Correction of the advance invoice (KOR_ZAL): the order shrinks to 4500.00 net, the customer gets 615.00 back');
$advanceCorrection = $example->invoice('KZAL')
    ->correction(new Correction([new CorrectedInvoice($advanceInvoice->issueDate, $advanceInvoice->number, $advanceKsef)], reason: 'Smaller order', amountBefore: Money::pln('1230.00')))   // P_15ZK: the payment BEFORE the correction
    ->advance(new AdvancePayment(Money::pln('-615.00'), VatRate::Rate23, new DateTimeImmutable('today')))   // the CHANGE of the payment
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '4500.00', VatRate::Rate23))
    ->build();
$send('KOR_ZAL', $advanceCorrection);

step('Correction of the final invoice (KOR_ROZ): the delivered scope was 4000.00 net after all');
$finalCorrection = $example->invoice('KROZ')
    ->correction(new Correction([new CorrectedInvoice($final->issueDate, $final->number, $finalKsef)], reason: 'Reduced scope', amountBefore: Money::pln('4920.00')))   // P_15ZK: what was left to pay BEFORE the correction
    ->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceKsef)], Money::pln('0.00')))   // advances paid: no change
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '4000.00', VatRate::Rate23))
    ->build();
$send('KOR_ROZ', $finalCorrection);

say("\nDone.");
