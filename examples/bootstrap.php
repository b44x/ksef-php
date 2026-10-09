<?php

declare(strict_types=1);

use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\Credentials;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceBuilder;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Testing\TestEnvironment;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Everything the examples have in common, so each example can focus on one idea.
 *
 * ZERO CONFIGURATION (default): the examples run against the public KSeF TEST environment. A throw-away
 * taxpayer with a self-signed certificate is created for you on every run - no account, token or certificate
 * needed. Just run `php examples/01-send-invoice.php`.
 *
 * YOUR OWN CREDENTIALS: set KSEF_ENV (test | demo | production), KSEF_NIP and either KSEF_TOKEN or
 * KSEF_CERT + KSEF_KEY (+ KSEF_KEY_PASSPHRASE). Never put secrets in code.
 */
final class Example
{
    public function __construct(
        public readonly KsefClient $ksef,
        public readonly Environment $environment,
        public readonly Nip $nip,
        public readonly ClientInterface $http,
        public readonly HttpFactory $factory,
        /** Self-signed credentials of the throw-away taxpayer (null when you use your own credentials). */
        public readonly ?Credentials $credentials,
    ) {}

    public function seller(): Seller
    {
        return new Seller($this->nip, 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1', '00-001 Warszawa'), 'billing@example.com');
    }

    public function buyer(): Buyer
    {
        return new Buyer(BuyerIdentifier::nip(Nip::of('5265877635')), 'Sample Buyer S.A.', Address::poland('ul. Długa 5', '80-001 Gdańsk'));
    }

    /** A builder pre-filled with seller, buyer, today's date and a unique invoice number. */
    public function invoice(string $prefix = 'FV'): InvoiceBuilder
    {
        return Invoice::builder()
            ->number(sprintf('%s/%s/%d', $prefix, date('Y-m-d'), random_int(1000, 999_999)))
            ->issueDate(new DateTimeImmutable('today'))
            ->seller($this->seller())
            ->buyer($this->buyer());
    }

    /** A second client with other credentials for the same taxpayer (for example a KSeF certificate or token). */
    public function clientWith(Credentials $credentials): KsefClient
    {
        return KsefClient::builder()
            ->environment($this->environment)
            ->httpClient($this->http, $this->factory, $this->factory)
            ->context(ContextIdentifier::nip($this->nip->value))
            ->credentials($credentials)
            ->build();
    }
}

function example(): Example
{
    // Timeouts belong to the HTTP client. Keep TLS verification enabled (the default).
    $http = new Client(['timeout' => 60, 'connect_timeout' => 10, 'http_errors' => false]);
    $factory = new HttpFactory();

    $selected = getenv('KSEF_ENV');
    if ($selected === false || $selected === '') {
        say('No credentials given: creating a throw-away taxpayer on the KSeF TEST environment ...');
        $taxpayer = TestEnvironment::createTaxpayer($http, $factory, $factory);
        say(sprintf('Test taxpayer NIP %s ready.', $taxpayer->nip->value));
        $credentials = $taxpayer->credentials();
        $ksef = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context($taxpayer->context())
            ->credentials($credentials)
            ->build();

        return new Example($ksef, Environment::Test, $taxpayer->nip, $http, $factory, $credentials);
    }

    $environment = match ($selected) {
        'test' => Environment::Test,
        'demo' => Environment::Demo,
        'production' => Environment::Production,
        default => throw new RuntimeException('KSEF_ENV must be test, demo or production.'),
    };
    if ($environment === Environment::Production && getenv('KSEF_ALLOW_PRODUCTION') !== '1') {
        throw new RuntimeException('These examples send real documents. Set KSEF_ALLOW_PRODUCTION=1 if you really mean to run them against production.');
    }
    $nip = getenv('KSEF_NIP');
    if ($nip === false || $nip === '') {
        throw new RuntimeException('Set KSEF_NIP to the NIP of the taxpayer you work for.');
    }

    $credentials = credentialsFromEnvironment();

    $ksef = KsefClient::builder()
        ->environment($environment)
        ->httpClient($http, $factory, $factory)
        ->context(ContextIdentifier::nip($nip))
        ->credentials($credentials)
        ->build();

    return new Example($ksef, $environment, Nip::unchecked($nip), $http, $factory, null);
}

function credentialsFromEnvironment(): Credentials
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

function say(string $message): void
{
    echo $message . "\n";
}

function step(string $title): void
{
    echo "\n== " . $title . "\n";
}
