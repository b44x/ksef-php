<?php

declare(strict_types=1);

/**
 * 14 - Signing outside the SDK (trusted profile, signature app, card reader).
 *
 *   php examples/14-manual-signing.php
 *
 * Many small businesses have no certificate: the person signs with a trusted profile (Profil Zaufany / ePUAP) or a
 * qualified signature app instead. The SDK cannot do that for them, so the login is split in two steps:
 *
 *   1. createAuthenticationRequest()      -> an XML document to sign (valid 10 minutes)
 *   2. (the person signs it elsewhere)    -> the same document with an enveloped XAdES signature
 *   3. authenticateWithSignedRequest()    -> KSeF verifies it and the client is logged in
 *
 * With a token store the tokens outlive the request, so the person signs once and later requests just work.
 * Here the throw-away test taxpayer's own certificate plays the part of the external signer.
 */

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\FileTokenStore;
use B4x\Ksef\Auth\ManualCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ManualSignatureRequiredException;
use B4x\Ksef\KsefClient;

require __DIR__ . '/bootstrap.php';

$example = example();
$store = new FileTokenStore(sys_get_temp_dir() . '/ksef-manual-demo-' . $example->nip->value);

$build = static fn(): KsefClient => KsefClient::builder()
    ->environment(Environment::Test)
    ->httpClient($example->http, $example->factory, $example->factory)
    ->context(ContextIdentifier::nip($example->nip->value))
    ->credentials(new ManualCredentials())
    ->tokenStore($store)
    ->build();

step('Request 1: nobody has signed yet');
$ksef = $build();
try {
    $ksef->contextLimits();
} catch (ManualSignatureRequiredException $e) {
    say('  as expected: ' . $e->getMessage());
}

step('Hand the request to the signer');
$request = $ksef->createAuthenticationRequest();
say('  challenge ' . $request->challenge . ', sign before ' . $request->expiresAt->format('H:i:s') . ' UTC');
say('  document to sign: ' . strlen($request->xml) . ' bytes of XML');

// In a real application you now show the document to the person (or send it to the signing service) and wait.
// The credentials of the throw-away taxpayer stand in for that step:
if (!$example->credentials instanceof CertificateCredentials) {
    say('  (this demo needs the throw-away taxpayer; with your own credentials sign the document in your signing tool)');
    exit(0);
}
$signed = $example->credentials->signer->sign($request->xml);

step('Complete the login with the signed document');
$ksef->authenticateWithSignedRequest($signed);
say('  logged in; online sessions may hold ' . $ksef->contextLimits()->onlineSession->maxInvoices . ' invoices');

step('Request 2: a new client, nobody signs, the tokens come from the store');
$later = $build();
say('  limits readable again: ' . $later->contextLimits()->batchSession->maxInvoices . ' invoices per batch');
