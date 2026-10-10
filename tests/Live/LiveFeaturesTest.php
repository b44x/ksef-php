<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Live;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Certificates\CertificateType;
use B4x\Ksef\Collective\CollectiveInvoice;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\AdvanceInvoiceReference;
use B4x\Ksef\Invoice\AdvancePayment;
use B4x\Ksef\Invoice\Attachment;
use B4x\Ksef\Invoice\AttachmentBlock;
use B4x\Ksef\Invoice\CorrectedInvoice;
use B4x\Ksef\Invoice\Correction;
use B4x\Ksef\Invoice\CorrectionAmount;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceBuilder;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Invoice\Settlement;
use B4x\Ksef\Invoice\ThirdParty;
use B4x\Ksef\Invoice\ThirdPartyRole;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Permissions\AuthorizationDirection;
use B4x\Ksef\Permissions\EntityAuthorizationType;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Rr\RrCorrection;
use B4x\Ksef\Rr\RrInvoice;
use B4x\Ksef\Rr\RrLine;
use B4x\Ksef\Rr\RrParty;
use B4x\Ksef\Rr\RrRate;
use B4x\Ksef\Testing\TestEnvironment;
use B4x\Ksef\Testing\TestTaxpayer;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * More end-to-end checks against the KSeF TEST environment (opt-in with KSEF_LIVE=1, see {@see LiveKsefTest}).
 */
final class LiveFeaturesTest extends TestCase
{
    private Client $http;
    private HttpFactory $factory;
    private PollingPolicy $policy;

    protected function setUp(): void
    {
        if (getenv('KSEF_LIVE') !== '1') {
            self::markTestSkipped('Live tests are opt-in: set KSEF_LIVE=1.');
        }
        $this->http = new Client(['timeout' => 60, 'connect_timeout' => 15, 'http_errors' => false]);
        $this->factory = new HttpFactory();
        $this->policy = new PollingPolicy(2.0, 5.0, 1.5, 180.0);
    }

    public function testCorrectionsOfAdvanceAndSettlementInvoicesAreAccepted(): void
    {
        [$taxpayer, $client] = $this->taxpayer();
        $today = new DateTimeImmutable('today');
        $base = fn(string $prefix): InvoiceBuilder => $this->builder($taxpayer, $prefix);

        $advance = $base('ZAL')->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, $today))->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23))->build();
        $advanceNumber = $this->accept($client, $advance);
        $settlement = $base('ROZ')->saleDate($today)->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceNumber)], Money::pln('1230.00')))->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23))->build();
        $settlementNumber = $this->accept($client, $settlement);

        $this->accept($client, $base('KZAL')
            ->correction(new Correction([new CorrectedInvoice($advance->issueDate, $advance->number, $advanceNumber)], amountBefore: Money::pln('1230.00')))
            ->advance(new AdvancePayment(Money::pln('-615.00'), VatRate::Rate23, $today))
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '4500.00', VatRate::Rate23))
            ->build());
        $this->accept($client, $base('KROZ')
            ->correction(new Correction([new CorrectedInvoice($settlement->issueDate, $settlement->number, $settlementNumber)], amountBefore: Money::pln('4920.00')))
            ->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceNumber)], Money::pln('0.00')))
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '5000.00', VatRate::Rate23)->asBefore())
            ->addLine(InvoiceLine::of('Software', '1', 'szt.', '4000.00', VatRate::Rate23))
            ->build());
    }

    public function testFarmerInvoicesNeedTheFarmersAuthorisationAndAreAccepted(): void
    {
        [$buyerTaxpayer, $buyerClient] = $this->taxpayer();
        [$farmer, $farmerClient] = $this->taxpayer();

        $farmerClient->grantAuthorization($buyerTaxpayer->nip, EntityAuthorizationType::RrInvoicing, 'Buyer', 'RR invoices', $this->policy);
        $granted = $farmerClient->authorizations(AuthorizationDirection::Granted)->items;
        self::assertSame('RRInvoicing', $granted[0]->scope);

        $address = \B4x\Ksef\Invoice\Address::poland('ul. Polna 1', '00-001 Wieś');
        $supplier = new RrParty($farmer->nip, 'Jan Rolnik', $address);
        $buyer = new RrParty($buyerTaxpayer->nip, 'Skup', $address);
        $today = new DateTimeImmutable('today');
        $invoice = RrInvoice::builder()->number('RR/' . random_int(1, 999_999))->issueDate($today)->purchaseDate($today)->supplier($supplier)->buyer($buyer)
            ->addLine(RrLine::of('Wheat', 'kg', '1500', 'A', '1.20', RrRate::Rate7))->build();
        $number = $this->accept($buyerClient, $invoice);

        $this->accept($buyerClient, RrInvoice::builder()->number('KRR/' . random_int(1, 999_999))->issueDate($today)->purchaseDate($today)->supplier($supplier)->buyer($buyer)
            ->correction(new RrCorrection([new CorrectedInvoice($today, $invoice->number, $number)]))
            ->addLine(RrLine::of('Wheat', 'kg', '1500', 'A', '1.20', RrRate::Rate7)->asBefore())
            ->addLine(RrLine::of('Wheat', 'kg', '1400', 'A', '1.20', RrRate::Rate7))
            ->build());
    }

    public function testOfflineInvoicesAreIssuedLocallyAndDeliveredLater(): void
    {
        [$taxpayer, $client] = $this->taxpayer();
        $certificate = $client->requestCertificate('offline live test', CertificateType::Offline, policy: $this->policy);

        $offline = $client->issueOfflineInvoice($this->builder($taxpayer, 'OFF')->addLine(InvoiceLine::of('Consulting', '3', 'h', '150.00', VatRate::Rate23))->build(), $certificate->toOfflineCertificate());
        self::assertStringContainsString('/certificate/Nip/' . $taxpayer->nip->value . '/', $offline->issuerUrl);

        $result = $client->waitForInvoice($client->sendOfflineInvoice($offline), $this->policy, true)->assertAccepted();
        self::assertNotNull($result->ksefNumber);
        $client->revokeCertificate($certificate->serialNumber);
    }

    public function testCollectiveIdentifiersAndAttachmentsInBatches(): void
    {
        [$taxpayer, $client] = $this->taxpayer();
        $numbers = [];
        foreach ([1, 2] as $_) {
            $numbers[] = $this->accept($client, $this->builder($taxpayer, 'FV')->addLine(InvoiceLine::of('Item', '1', 'szt.', '100.00', VatRate::Rate23))->build());
        }

        $id = $client->createCollectiveIdentifier([new CollectiveInvoice($numbers[0], Money::pln('123.00')), new CollectiveInvoice($numbers[1])]);
        self::assertStringContainsString('-IZ', $id);
        $invoices = $client->collectiveIdentifierInvoices([$id]);
        self::assertCount(2, $invoices->items);

        // Attachments: consent, batch only.
        TestEnvironment::allowAttachments($taxpayer->nip, $this->http, $this->factory, $this->factory);
        $rich = $this->builder($taxpayer, 'ATT')
            ->addLine(InvoiceLine::of('Services', '1', 'szt.', '100.00', VatRate::Rate23)->withDiscount('10.00'))
            ->addThirdParty(ThirdParty::of(ThirdPartyRole::Recipient, \B4x\Ksef\Invoice\BuyerIdentifier::nip(\B4x\Ksef\Support\Nip::of('5265877635')), 'Branch'))
            ->attachment(new Attachment([new AttachmentBlock(['Period' => '2026-10'], 'Statement', ['Services rendered.'])]))
            ->build();
        $batch = $client->sendBatch([$rich]);
        $status = $client->waitForSession($batch->sessionReference, $this->policy);
        self::assertSame(1, $status->successfulInvoiceCount);
    }

    public function testCollectiveCorrectionIsAccepted(): void
    {
        [$taxpayer, $client] = $this->taxpayer();
        $original = $this->builder($taxpayer, 'FV')->addLine(InvoiceLine::of('Item', '1', 'szt.', '100.00', VatRate::Rate23))->build();
        $number = $this->accept($client, $original);

        $correction = $this->builder($taxpayer, 'KOR')
            ->correction(new Correction(
                [new CorrectedInvoice(new DateTimeImmutable('today'), $original->number, $number)],
                reason: 'Volume discount',
                period: date('Y-m-01') . ' - ' . date('Y-m-d'),
                amounts: [CorrectionAmount::of(VatRate::Rate23, '-10.00', '-2.30')],
            ))
            ->build();

        $this->accept($client, $correction);
    }

    public function testAnInterruptedBatchUploadSurfacesTheOriginalErrorAndKsefKeepsTheSessionOpen(): void
    {
        [$taxpayer] = $this->taxpayer();
        $failing = new class ($this->http) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly \Psr\Http\Client\ClientInterface $inner) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                if ($request->getMethod() === 'PUT') {
                    return new \GuzzleHttp\Psr7\Response(403, [], 'denied');
                }

                return $this->inner->sendRequest($request);
            }
        };
        $client = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($failing, $this->factory, $this->factory)
            ->context(ContextIdentifier::nip($taxpayer->nip->value))
            ->credentials($taxpayer->credentials())
            ->build();

        $invoice = $this->builder($taxpayer, 'FV')->addLine(InvoiceLine::of('Item', '1', 'szt.', '100.00', VatRate::Rate23))->build();
        try {
            $client->sendBatch([$invoice]);
            self::fail('The upload was refused, so the batch must fail.');
        } catch (\B4x\Ksef\Exception\ApiException $e) {
            // KSeF has no cancel for batch sessions and refuses to close one with missing parts (21205):
            // the SDK reports the upload error as it is and does not pretend to clean up.
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testPeppolProvidersSendPefInvoicesOnBehalfOfACompany(): void
    {
        [$company, $companyClient] = $this->taxpayer();
        $provider = TestEnvironment::createPeppolProvider();
        $providerClient = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($this->http, $this->factory, $this->factory)
            ->context($provider->context())
            ->credentials($provider->credentials())
            ->build();

        $providerClient->openOnlineSession(FormCode::pef())->close(); // first sign-in registers the provider
        $companyClient->grantAuthorization($provider->id, EntityAuthorizationType::PefInvoicing, 'Test Peppol provider', 'PEF invoicing', $this->policy);

        $xml = strtr((string) file_get_contents(__DIR__ . '/../../examples/fixtures/pef-invoice.xml'), [
            '{{NUMBER}}' => 'PEF/' . random_int(1000, 999_999),
            '{{DATE}}' => date('Y-m-d'),
            '{{SELLER_NIP}}' => $company->nip->value,
            '{{BUYER_NIP}}' => '5265877635',
        ]);
        $session = $providerClient->openOnlineSession(FormCode::pef());
        $result = $providerClient->waitForInvoice($session->send(InvoiceDocument::fromXml($xml)), $this->policy, true)->assertAccepted();
        $session->close();
        self::assertStringStartsWith($company->nip->value . '-', (string) $result->ksefNumber);

        // The correction template of the Ministry of Finance carries an attachment: the seller must allow it first.
        TestEnvironment::allowAttachments($company->nip, $this->http, $this->factory, $this->factory);
        $correction = InvoiceDocument::fromXml(strtr((string) file_get_contents(__DIR__ . '/../../examples/fixtures/pef-correction.xml'), [
            '#supplier_nip#' => 'PL' . $company->nip->value,
            '#buyer_nip#' => 'PL5265877635',
            '#buyer_reference#' => 'PL5265877635',
            '#iban#' => 'PL61109010140000071219812874',
            '#invoice_number#' => 'KOR/' . random_int(1000, 999_999),
            '#issue_date#' => date('Y-m-d'),
            '#due_date#' => date('Y-m-d', strtotime('+14 days')),
            '#ksef_number#' => (string) $result->ksefNumber,
        ]));
        $correctionSession = $providerClient->openOnlineSession(FormCode::pefCorrection());
        $providerClient->waitForInvoice($correctionSession->send($correction), $this->policy, true)->assertAccepted();
        $correctionSession->close();
    }

    /**
     * @return array{TestTaxpayer, KsefClient}
     */
    private function taxpayer(): array
    {
        $taxpayer = TestEnvironment::createTaxpayer($this->http, $this->factory, $this->factory);
        $client = KsefClient::builder()
            ->environment(Environment::Test)
            ->httpClient($this->http, $this->factory, $this->factory)
            ->context(ContextIdentifier::nip($taxpayer->nip->value))
            ->credentials($taxpayer->credentials())
            ->build();

        return [$taxpayer, $client];
    }

    private function builder(TestTaxpayer $taxpayer, string $prefix): InvoiceBuilder
    {
        return Invoice::builder()
            ->number($prefix . '/' . date('Ymd') . '/' . random_int(1, 999_999))
            ->issueDate(new DateTimeImmutable('today'))
            ->seller(new \B4x\Ksef\Invoice\Seller($taxpayer->nip, 'Live Seller', \B4x\Ksef\Invoice\Address::poland('ul. Testowa 1', '00-001 Warszawa')))
            ->buyer(new \B4x\Ksef\Invoice\Buyer(\B4x\Ksef\Invoice\BuyerIdentifier::nip(\B4x\Ksef\Support\Nip::of('5265877635')), 'Live Buyer', \B4x\Ksef\Invoice\Address::poland('ul. Kupiecka 2', '00-002 Warszawa')));
    }

    private function accept(KsefClient $client, Invoice|RrInvoice $invoice): string
    {
        $result = $client->waitForInvoice($client->sendInvoice($invoice), $this->policy, true)->assertAccepted();
        self::assertNotNull($result->ksefNumber);

        return $result->ksefNumber;
    }
}
