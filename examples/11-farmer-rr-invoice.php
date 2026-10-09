<?php

declare(strict_types=1);

/**
 * 11 - Flat-rate farmer purchase invoices (FA_RR): you buy from a farmer and issue the invoice yourself.
 *
 *   php examples/11-farmer-rr-invoice.php
 *
 * The buyer issues the invoice, so KSeF needs the farmer's consent first: the farmer authorises the buyer with
 * the RRInvoicing authorisation (done once, in KSeF or via the API as below). On TEST this example creates both
 * companies for you.
 */

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Permissions\EntityAuthorizationType;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Rr\RrCorrection;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Rr\RrLine;
use B4x\Ksef\Rr\RrParty;
use B4x\Ksef\Rr\RrPayment;
use B4x\Ksef\Rr\RrRate;
use B4x\Ksef\Testing\TestEnvironment;

require __DIR__ . '/bootstrap.php';

$buyerCompany = example();   // the buyer: it issues the invoice
$policy = new PollingPolicy(timeoutSeconds: 120.0);

step('1. The farmer (a second taxpayer) authorises the buyer to issue RR invoices');
$farmer = TestEnvironment::createTaxpayer($buyerCompany->http, $buyerCompany->factory, $buyerCompany->factory);
$farmerClient = KsefClient::builder()
    ->environment($buyerCompany->environment)
    ->httpClient($buyerCompany->http, $buyerCompany->factory, $buyerCompany->factory)
    ->context(ContextIdentifier::nip($farmer->nip->value))
    ->credentials($farmer->credentials())
    ->build();
$farmerClient->grantAuthorization($buyerCompany->nip, EntityAuthorizationType::RrInvoicing, 'Skup Zbóż sp. z o.o.', 'RR invoices');
say('  farmer ' . $farmer->nip->value . ' -> buyer ' . $buyerCompany->nip->value . ': RRInvoicing granted');

step('2. The buyer issues the RR invoice');
$supplier = new RrParty($farmer->nip, 'Jan Rolnik', new Address('PL', 'Wieś 1', '00-001 Wieś'));
$buyer = new RrParty($buyerCompany->nip, 'Skup Zbóż sp. z o.o.', new Address('PL', 'ul. Skupowa 2', '00-002 Miasto'));
$invoice = RrInvoice::builder()
    ->number('RR/' . date('Y-m-d') . '/' . random_int(1000, 999_999))
    ->issueDate(new DateTimeImmutable('today'))
    ->purchaseDate(new DateTimeImmutable('today'))
    ->supplier($supplier)
    ->buyer($buyer)
    ->addLine(RrLine::of('Wheat', 'kg', '1500', 'class A', '1.20', RrRate::Rate7))                 // 7 % refund on products
    ->addLine(RrLine::of('Harvesting service', 'h', '10', 'standard', '99.99', RrRate::Rate6_5))   // 6.5 % on services
    ->payment(RrPayment::transfer())
    ->build();
say(sprintf('  price %s + refund %s = %s', $invoice->value(), $invoice->refund(), $invoice->total()));
say('  in words: ' . $invoice->totalInWords());
$result = $buyerCompany->ksef->waitForInvoice($buyerCompany->ksef->sendInvoice($invoice), $policy, untilStored: true)->assertAccepted();
say('  accepted: ' . $result->ksefNumber);

step('3. Correct it (KOR_VAT_RR): only 1400 kg were delivered');
$correction = RrInvoice::builder()
    ->number('KRR/' . date('Y-m-d') . '/' . random_int(1000, 999_999))
    ->issueDate(new DateTimeImmutable('today'))
    ->purchaseDate(new DateTimeImmutable('today'))
    ->supplier($supplier)
    ->buyer($buyer)
    ->correction(new RrCorrection([new CorrectedInvoice($invoice->issueDate, $invoice->number, $result->ksefNumber)], reason: 'Lower quantity'))
    ->addLine(RrLine::of('Wheat', 'kg', '1500', 'class A', '1.20', RrRate::Rate7)->asBefore())
    ->addLine(RrLine::of('Wheat', 'kg', '1400', 'class A', '1.20', RrRate::Rate7))
    ->build();
say(sprintf('  the correction changes the total by %s', $correction->total()));
$corrected = $buyerCompany->ksef->waitForInvoice($buyerCompany->ksef->sendInvoice($correction), $policy, untilStored: true)->assertAccepted();
say('  accepted: ' . $corrected->ksefNumber);

say("\nDone.");
