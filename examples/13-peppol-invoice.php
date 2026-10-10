<?php

declare(strict_types=1);

/**
 * 13 - Peppol (PEF) invoices: a Peppol service provider sends a UBL invoice into KSeF on behalf of a company.
 *
 *   php examples/13-peppol-invoice.php
 *
 * Roles: the COMPANY (seller) authorises the PROVIDER once with the PefInvoicing authorisation; the PROVIDER signs
 * in with its own Peppol certificate (context type PeppolId) and sends PEF documents with the PEF (3) form code
 * (PEF_KOR (3) for credit notes).
 * The SDK does not build UBL for you - Peppol providers already have their own tooling - it verifies the XML
 * against the official schema and does the transport. On TEST this example creates both parties for you.
 */

use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Permissions\EntityAuthorizationType;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Testing\TestEnvironment;

require __DIR__ . '/bootstrap.php';

$company = example();
if ($company->environment !== Environment::Test) {
    say('This example plays both roles, which is only possible on the TEST environment.');
    exit(0);
}

step('1. The Peppol provider signs in once (KSeF registers it automatically on the first sign-in)');
$provider = TestEnvironment::createPeppolProvider();
$providerClient = KsefClient::builder()
    ->environment($company->environment)
    ->httpClient($company->http, $company->factory, $company->factory)
    ->context($provider->context())          // context type PeppolId
    ->credentials($provider->credentials())  // certificate whose common name is the provider's Peppol ID
    ->build();
$providerClient->openOnlineSession(FormCode::pef())->close();
say('  provider ' . $provider->id . ' is registered');

step('2. The company authorises the provider to issue PEF invoices for it (needs the step above first)');
$company->ksef->grantAuthorization($provider->id, EntityAuthorizationType::PefInvoicing, 'Test Peppol provider', 'PEF invoicing');
say(sprintf('  company %s -> provider %s: PefInvoicing granted', $company->nip->value, $provider->id));

step('3. The provider sends the UBL invoice of the company');
$xml = strtr((string) file_get_contents(__DIR__ . '/fixtures/pef-invoice.xml'), [
    '{{NUMBER}}' => 'PEF/' . date('Ymd') . '/' . random_int(1000, 999_999),
    '{{DATE}}' => date('Y-m-d'),
    '{{SELLER_NIP}}' => $company->nip->value,
    '{{BUYER_NIP}}' => '5265877635',
]);
$document = InvoiceDocument::fromXml($xml);   // recognised as PEF (3) from the root element and checked against the XSD
say('  form: ' . $document->formCode->systemCode);
$session = $providerClient->openOnlineSession(FormCode::pef());
$result = $providerClient->waitForInvoice($session->send($document), new PollingPolicy(timeoutSeconds: 120.0), untilStored: true)->assertAccepted();
$session->close();
say('  accepted: ' . $result->ksefNumber . '   (numbered under the SELLER, not the provider)');

step('4. The provider corrects it with a PEF_KOR credit note');
// The Ministry of Finance template for corrections carries an attachment, which the seller must have allowed first.
TestEnvironment::allowAttachments($company->nip, $company->http, $company->factory, $company->factory);
$correction = InvoiceDocument::fromXml(strtr((string) file_get_contents(__DIR__ . '/fixtures/pef-correction.xml'), [
    '#supplier_nip#' => 'PL' . $company->nip->value,
    '#buyer_nip#' => 'PL5265877635',
    '#buyer_reference#' => 'PL5265877635',
    '#iban#' => 'PL61109010140000071219812874',
    '#invoice_number#' => 'KOR/' . random_int(1000, 999_999),
    '#issue_date#' => date('Y-m-d'),
    '#due_date#' => date('Y-m-d', strtotime('+14 days')),
    '#ksef_number#' => (string) $result->ksefNumber,
]));
say('  form: ' . $correction->formCode->systemCode);
$session = $providerClient->openOnlineSession(FormCode::pefCorrection());
$corrected = $providerClient->waitForInvoice($session->send($correction), new PollingPolicy(timeoutSeconds: 120.0), untilStored: true)->assertAccepted();
$session->close();
say('  accepted: ' . $corrected->ksefNumber);

say("\nDone.");
