<?php

declare(strict_types=1);

/**
 * 06 - From a one-off certificate to production-grade credentials.
 *
 *   php examples/06-certificates-and-tokens.php
 *
 * Qualified certificates are verified by KSeF against the issuer (OCSP/CRL), which can make logging in slow.
 * KSeF recommends a KSeF certificate for production: you sign in once with your qualified certificate, request a
 * KSeF certificate and use that from then on. A KSeF token is the simplest option for servers that only
 * need to send and read invoices.
 */

use B4x\Ksef\Api\TokenPermission;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Polling\PollingPolicy;

require __DIR__ . '/bootstrap.php';

$example = example();
$ksef = $example->ksef;                       // logged in with a signature (needed to request certificates)
$policy = new PollingPolicy(timeoutSeconds: 120.0);

step('Limits');
$limits = $ksef->certificateLimits();
say(sprintf('  you may request %d more certificate(s)', $limits->enrollmentRemaining));

step('1. Request a KSeF certificate (a new EC P-256 key is generated locally)');
$certificate = $ksef->requestCertificate('billing service', CertificateType::Authentication, policy: $policy);
say('  serial number: ' . $certificate->serialNumber);
say('  STORE $certificate->certificatePem and $certificate->privateKeyPem in your secret manager NOW: the key exists nowhere else.');

step('2. Log in with it from now on');
$withCertificate = $example->clientWith($certificate->toCredentials());
$session = $withCertificate->openOnlineSession();
say('  opened a session as the KSeF certificate: ' . $session->referenceNumber);
$session->close();

step('3. Alternatively: a KSeF token (secret shown once, valid after its status turns Active)');
$token = $ksef->generateToken([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'billing service token');
say('  status: ' . $ksef->waitForToken($token->referenceNumber, $policy)->value);
$withToken = $example->clientWith(new KsefTokenCredentials($token->token));
$session = $withToken->openOnlineSession();
say('  opened a session with the token: ' . $session->referenceNumber);
$session->close();

step('4. Clean up the demo credentials');
$ksef->revokeToken($token->referenceNumber);
$ksef->revokeCertificate($certificate->serialNumber);
say('  revoked.');
