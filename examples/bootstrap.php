<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ksef\Auth\CertificateCredentials;
use Ksef\Auth\ContextIdentifier;
use Ksef\Auth\Credentials;
use Ksef\Auth\KsefTokenCredentials;
use Ksef\Environment;
use Ksef\KsefClient;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Builds a client from environment variables. Secrets come from the environment (or your secret
 * manager) and are never hardcoded.
 *
 *   KSEF_ENV      test | demo | production        (no default on purpose)
 *   KSEF_NIP      NIP of the context (the taxpayer you work for)
 *   KSEF_TOKEN    a KSeF token ... or ...
 *   KSEF_CERT / KSEF_KEY / KSEF_KEY_PASSPHRASE   paths to a PEM certificate and private key
 */
function createClient(): KsefClient
{
    $environment = match (getenv('KSEF_ENV')) {
        'test' => Environment::Test,
        'demo' => Environment::Demo,
        'production' => Environment::Production,
        default => throw new RuntimeException('Set KSEF_ENV to test, demo or production.'),
    };

    $nip = getenv('KSEF_NIP');
    if ($nip === false || $nip === '') {
        throw new RuntimeException('Set KSEF_NIP.');
    }

    $credentials = createCredentials();
    $factory = new HttpFactory();

    return KsefClient::builder()
        ->environment($environment)
        // Timeouts and TLS verification belong to the HTTP client: keep verification enabled.
        ->httpClient(new Client(['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false]), $factory, $factory)
        ->context(ContextIdentifier::nip($nip))
        ->credentials($credentials)
        ->build();
}

function createCredentials(): Credentials
{
    $token = getenv('KSEF_TOKEN');
    if ($token !== false && $token !== '') {
        return new KsefTokenCredentials($token);
    }

    $certificate = getenv('KSEF_CERT');
    $key = getenv('KSEF_KEY');
    if ($certificate === false || $key === false) {
        throw new RuntimeException('Set KSEF_TOKEN, or KSEF_CERT and KSEF_KEY.');
    }
    $passphrase = getenv('KSEF_KEY_PASSPHRASE');

    return CertificateCredentials::fromPemFiles($certificate, $key, $passphrase === false ? null : $passphrase);
}
