<?php

declare(strict_types=1);

namespace Ksef\Tests\Live;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ksef\Api\TokenPermission;
use Ksef\Api\TokenStatus;
use Ksef\Auth\CertificateCredentials;
use Ksef\Auth\ContextIdentifier;
use Ksef\Auth\KsefTokenCredentials;
use Ksef\Environment;
use Ksef\Invoice\Address;
use Ksef\Invoice\Buyer;
use Ksef\Invoice\BuyerIdentifier;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceLine;
use Ksef\Invoice\Seller;
use Ksef\Invoice\VatRate;
use Ksef\KsefClient;
use Ksef\Polling\PollingPolicy;
use Ksef\Support\Nip;
use Ksef\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

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
        $nip = $this->randomNip();
        $http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();

        $this->createTestTaxpayer($http, $factory, $nip);

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

        $result = $client->waitForInvoice($submission, new PollingPolicy(2.0, 5.0, 1.5, 120.0))->assertAccepted();
        self::assertNotNull($result->ksefNumber);

        $upo = $client->invoiceUpo($submission);
        self::assertTrue($upo->verifyHash());
        self::assertStringContainsString('<', $upo->xml);

        $downloaded = $client->downloadInvoice($result->ksefNumber, new PollingPolicy(1.0, 3.0, 1.5, 60.0));
        self::assertTrue($downloaded->verifyHash());
        self::assertStringContainsString('<P_2>' . $invoice->number . '</P_2>', $downloaded->xml);

        // Sending the very same document again must be recognised as a duplicate, not stored twice.
        $second = $client->waitForInvoice($client->sendInvoice($invoice), new PollingPolicy(2.0, 5.0, 1.5, 120.0));
        self::assertTrue($second->status->isDuplicate(), 'status: ' . $second->status->code . ' ' . $second->status->description);
        self::assertSame($result->ksefNumber, $second->status->originalKsefNumber());
    }

    public function testKsefTokenAuthenticationOnTheTestEnvironment(): void
    {
        $nip = $this->randomNip();
        $http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $factory = new HttpFactory();
        $this->createTestTaxpayer($http, $factory, $nip);

        $pki = TestPki::personal($nip);
        $builder = static fn(\Ksef\Auth\Credentials $credentials): KsefClient => KsefClient::builder()
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

    private function createTestTaxpayer(Client $http, HttpFactory $factory, string $nip): void
    {
        $body = json_encode(['nip' => $nip, 'pesel' => $this->randomPesel(), 'description' => 'ksef-php live test', 'isBailiff' => false], JSON_THROW_ON_ERROR);
        $request = $factory->createRequest('POST', Environment::Test->baseUrl() . '/testdata/person')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));
        $response = $http->sendRequest($request);

        self::assertContains($response->getStatusCode(), [200, 201], 'Cannot create the TEST taxpayer: ' . $response->getBody());
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
