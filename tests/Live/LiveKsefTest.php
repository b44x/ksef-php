<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Live;

use B4x\Ksef\Api\TokenPermission;
use B4x\Ksef\Api\TokenStatus;
use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\TestPki;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * End-to-end test against the public KSeF TEST environment.
 *
 * Skipped unless KSEF_LIVE=1. It needs network access, creates a throw-away taxpayer through the
 * TEST-only /testdata API and authenticates with a self-signed certificate (accepted on TEST only).
 * No credentials are required and no production system is touched.
 */
final class LiveKsefTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('KSEF_LIVE') !== '1') {
            self::markTestSkipped('Live tests are opt-in: set KSEF_LIVE=1.');
        }
    }

    public function testFullInvoiceLifecycleOnTheTestEnvironment(): void
    {
        $http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();

        $nip = $this->createTestTaxpayer($http, $factory);

        $pki = TestPki::personal($nip);
        $client = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context(ContextIdentifier::nip($nip))
            ->credentials(CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']))
            ->build();

        $invoice = Invoice::builder()
            ->number('LIVE/' . date('Ymd-His') . '/' . random_int(100, 999))
            ->issueDate(new DateTimeImmutable('today'))
            ->saleDate(new DateTimeImmutable('today'))
            ->seller(new Seller(Nip::unchecked($nip), 'Live Test Seller', Address::poland('ul. Testowa 1', '00-001 Warszawa')))
            ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('5265877635')), 'Live Test Buyer', Address::poland('ul. Kupiecka 2', '00-002 Warszawa')))
            ->addLine(InvoiceLine::of('Live test service', '2', 'szt.', '100.00', VatRate::Rate23))
            ->build();

        $submission = $client->sendInvoice($invoice);
        self::assertNotSame('', $submission->invoiceReference);

        $result = $client->waitForInvoice($submission, new PollingPolicy(2.0, 5.0, 1.5, 120.0), true)->assertAccepted();
        self::assertNotNull($result->ksefNumber);

        $upo = $client->invoiceUpo($submission);
        self::assertTrue($upo->verifyHash());
        self::assertStringContainsString('<', $upo->xml);

        // waitForInvoice(..., untilStored) already guarantees the document is downloadable: no 406 handling needed.
        $downloaded = $client->downloadInvoice($result->ksefNumber);
        self::assertTrue($downloaded->verifyHash());
        self::assertStringContainsString('<P_2>' . $invoice->number . '</P_2>', $downloaded->xml);

        // Sending the very same document again must be recognised as a duplicate, not stored twice.
        $second = $client->waitForInvoice($client->sendInvoice($invoice), new PollingPolicy(2.0, 5.0, 1.5, 120.0));
        self::assertTrue($second->status->isDuplicate(), 'status: ' . $second->status->code . ' ' . $second->status->description);
        self::assertSame($result->ksefNumber, $second->status->originalKsefNumber());
    }

    public function testBatchSessionOnTheTestEnvironment(): void
    {
        $http = new Client(['timeout' => 120, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();
        $nip = $this->createTestTaxpayer($http, $factory);

        $pki = TestPki::personal($nip);
        $client = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context(ContextIdentifier::nip($nip))
            ->credentials(CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']))
            ->build();

        $invoices = [];
        $prefix = 'BATCH/' . date('Ymd-His') . '/';
        for ($i = 1; $i <= 5; ++$i) {
            $invoices[] = Invoice::builder()
                ->number($prefix . $i)
                ->issueDate(new DateTimeImmutable('today'))
                ->seller(new Seller(Nip::unchecked($nip), 'Live Batch Seller', Address::poland('ul. Testowa 1', '00-001 Warszawa')))
                ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('5265877635')), 'Live Batch Buyer'))
                ->addLine(InvoiceLine::of('Batch item ' . $i, '1', 'szt.', '10.00', VatRate::Rate23))
                ->build();
        }

        $submission = $client->sendBatch($invoices);
        $status = $client->waitForSession($submission->sessionReference, new PollingPolicy(2.0, 5.0, 1.5, 180.0));

        self::assertTrue($status->isSuccessful(), 'session: ' . $status->code . ' ' . $status->description);
        self::assertSame(5, $status->successfulInvoiceCount);
        self::assertSame(0, $status->failedInvoiceCount);
        $page = $client->sessionInvoices($submission->sessionReference);
        self::assertCount(5, $page->invoices);
        foreach ($page->invoices as $invoice) {
            self::assertContains($invoice->invoiceHash, $submission->invoiceHashes);
            self::assertNotNull($invoice->ksefNumber);
        }
    }

    public function testKsefCertificatesAreIssuedAndUsableOnTheTestEnvironment(): void
    {
        $http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();
        $nip = $this->createTestTaxpayer($http, $factory);

        $pki = TestPki::personal($nip);
        $builder = static fn(\B4x\Ksef\Auth\Credentials $credentials): KsefClient => KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context(ContextIdentifier::nip($nip))
            ->credentials($credentials)
            ->build();
        $client = $builder(CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']));
        $policy = new PollingPolicy(1.0, 3.0, 1.5, 60.0);

        foreach ([\B4x\Ksef\Certificates\KeyType::EcP256, \B4x\Ksef\Certificates\KeyType::Rsa2048] as $keyType) {
            $authentication = $client->requestCertificate('ksef-php live ' . $keyType->name, \B4x\Ksef\Certificates\CertificateType::Authentication, $keyType, $policy);
            self::assertMatchesRegularExpression('/^[0-9A-F]+$/', $authentication->serialNumber);

            // The freshly issued KSeF certificate authenticates a new session.
            $session = $builder($authentication->toCredentials())->openOnlineSession();
            self::assertNotSame('', $session->referenceNumber);
            $session->close();
        }

        $offline = $client->requestCertificate('ksef-php live offline', \B4x\Ksef\Certificates\CertificateType::Offline, policy: $policy);
        $found = $client->searchCertificates(\B4x\Ksef\Certificates\CertificateType::Offline);
        self::assertContains($offline->serialNumber, array_map(static fn(\B4x\Ksef\Certificates\CertificateInfo $c): string => $c->serialNumber, $found['certificates']));

        $url = (new \B4x\Ksef\Qr\VerificationLinks(Environment::Test))->certificateUrl(ContextIdentifier::nip($nip), Nip::unchecked($nip), base64_encode(hash('sha256', 'x', true)), $offline->toOfflineCertificate());
        self::assertStringContainsString('/certificate/Nip/' . $nip . '/' . $nip . '/' . $offline->serialNumber . '/', $url);

        $client->revokeCertificate($offline->serialNumber);
    }

    public function testExportLimitsAndAuthSessionsOnTheTestEnvironment(): void
    {
        $http = new Client(['timeout' => 120, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();
        $nip = $this->createTestTaxpayer($http, $factory);

        $pki = TestPki::personal($nip);
        $client = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context(ContextIdentifier::nip($nip))
            ->credentials(CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']))
            ->build();
        $policy = new PollingPolicy(2.0, 5.0, 1.5, 180.0);

        $invoice = Invoice::builder()
            ->number('EXPORT/' . date('Ymd-His'))
            ->issueDate(new DateTimeImmutable('today'))
            ->seller(new Seller(Nip::unchecked($nip), 'Live Export Seller', Address::poland('ul. Testowa 1', '00-001 Warszawa')))
            ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('5265877635')), 'Live Export Buyer'))
            ->addLine(InvoiceLine::of('Export item', '1', 'szt.', '10.00', VatRate::Rate23))
            ->build();
        $result = $client->waitForInvoice($client->sendInvoice($invoice), $policy, true)->assertAccepted();

        $path = tempnam(sys_get_temp_dir(), 'ksef-export-');
        self::assertIsString($path);
        $package = $client->exportInvoices(\B4x\Ksef\Api\InvoiceSubjectType::Seller, \B4x\Ksef\Api\InvoiceDateType::PermanentStorage, new DateTimeImmutable('-1 day'), null, $path, $policy);
        self::assertSame(1, $package->invoiceCount);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertNotFalse($zip->locateName($result->ksefNumber . '.xml'));
        self::assertNotFalse($zip->locateName('_metadata.json'));
        $zip->close();
        unlink($path);

        $limits = $client->contextLimits();
        self::assertGreaterThan(0, $limits->onlineSession->maxInvoices);
        self::assertArrayHasKey('invoiceSend', $client->rateLimits());

        $sessions = $client->authSessions();
        self::assertNotSame([], $sessions['sessions']);
        self::assertNotSame([], array_filter($sessions['sessions'], static fn(\B4x\Ksef\Auth\AuthSession $s): bool => $s->isCurrent));
    }

    public function testKsefTokenAuthenticationOnTheTestEnvironment(): void
    {
        $http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();
        $nip = $this->createTestTaxpayer($http, $factory);

        $pki = TestPki::personal($nip);
        $builder = static fn(\B4x\Ksef\Auth\Credentials $credentials): KsefClient => KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($http, $factory, $factory)
            ->context(ContextIdentifier::nip($nip))
            ->credentials($credentials)
            ->build();

        $certificateClient = $builder(CertificateCredentials::fromPem($pki['certificatePem'], $pki['privateKeyPem']));
        $token = $certificateClient->generateToken([TokenPermission::InvoiceRead, TokenPermission::InvoiceWrite], 'ksef-php live test');
        self::assertSame(TokenStatus::Active, $certificateClient->waitForToken($token->referenceNumber, new PollingPolicy(2.0, 5.0, 1.5, 120.0)));

        // Authenticate with the token itself: exercises RSA-OAEP encryption of `token|timestamp`.
        $tokenClient = $builder(new KsefTokenCredentials($token->token));
        $session = $tokenClient->openOnlineSession();
        self::assertNotSame('', $session->referenceNumber);
        $session->close();

        $certificateClient->revokeToken($token->referenceNumber);
    }

    /**
     * Creates a throw-away taxpayer on TEST and returns its NIP. Random NIPs can collide with subjects
     * created by earlier runs ("already exists"), so a few fresh ones are tried.
     */
    private function createTestTaxpayer(Client $http, HttpFactory $factory): string
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $nip = $this->randomNip();
            $body = json_encode(['nip' => $nip, 'pesel' => $this->randomPesel(), 'description' => 'ksef-php live test', 'isBailiff' => false], JSON_THROW_ON_ERROR);
            $request = $factory->createRequest('POST', Environment::Test->baseUrl() . '/testdata/person')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
            $response = $http->sendRequest($request);

            if (\in_array($response->getStatusCode(), [200, 201], true)) {
                return $nip;
            }
            $lastError = (string) $response->getBody();
        }

        self::fail('Cannot create the TEST taxpayer: ' . $lastError);
    }

    private function randomNip(): string
    {
        while (true) {
            $digits = (string) random_int(1, 9) . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += $weight * (int) $digits[$i];
            }
            $candidate = $digits . ($sum % 11);
            if ($sum % 11 < 10 && Nip::hasValidChecksum($candidate) && preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $candidate) === 1) {
                return $candidate;
            }
        }
    }

    private function randomPesel(): string
    {
        $digits = '900101' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        return $digits . ((10 - $sum % 10) % 10);
    }
}
