<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Qr\OfflineCertificate;
use B4x\Ksef\Session\SendOptions;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\TestPki;

final class OfflineFlowTest extends KsefTestCase
{
    public function testAnOfflineInvoiceCarriesBothQrLinksAndIsDeliveredWithTheOfflineFlag(): void
    {
        $pki = TestPki::seal('5265877635');
        $certificate = new OfflineCertificate($pki['certificatePem'], $pki['privateKeyPem']);
        $client = $this->client();
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);

        $offline = $client->issueOfflineInvoice(Fixtures::standardInvoice(), $certificate);

        self::assertStringStartsWith('https://qr-test.ksef.mf.gov.pl/invoice/5265877635/01-06-2026/', $offline->verificationUrl);
        self::assertStringStartsWith('https://qr-test.ksef.mf.gov.pl/certificate/Nip/5265877635/5265877635/' . $certificate->serialNumber() . '/', $offline->issuerUrl);
        self::assertSame('OFFLINE', $offline->caption);
        self::assertStringContainsString('/' . rtrim(strtr($offline->document->hash(), '+/', '-_'), '=') . '/', $offline->issuerUrl);
        self::assertCount(0, $this->ksef->requestsTo('POST', '/sessions/online'), 'issuing needs no network access');

        $client->sendOfflineInvoice($offline);

        $body = FakeKsef::body($this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices')[0]);
        self::assertTrue($body['offlineMode']);
        self::assertArrayNotHasKey('hashOfCorrectedInvoice', $body);
    }

    public function testATechnicalCorrectionLinksTheRejectedInvoiceByItsHash(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $rejected = InvoiceDocument::fromInvoice(Fixtures::standardInvoice());

        $this->client()->sendTechnicalCorrection(Fixtures::standardInvoice(), $rejected);

        $body = FakeKsef::body($this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices')[0]);
        self::assertTrue($body['offlineMode']);
        self::assertSame($rejected->hash(), $body['hashOfCorrectedInvoice']);
    }

    public function testTheCorrectedHashIsValidated(): void
    {
        $this->expectException(ValidationException::class);
        SendOptions::technicalCorrection('not-a-hash');
    }
}
