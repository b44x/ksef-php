<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\InvoiceRejectedException;
use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Exception\PollingTimeoutException;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Exception\SubmissionOutcomeUnknownException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\Http;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;

final class InvoiceSubmissionFlowTest extends KsefTestCase
{
    private const KSEF_NUMBER = '5265877635-20260601-0100001AF629-15';

    public function testSendingAnInvoiceEncryptsItAndYieldsAnAcceptedForProcessingSubmission(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);

        $invoice = Fixtures::standardInvoice();
        $submission = $this->client()->sendInvoice($invoice);

        self::assertSame('sess-1', $submission->sessionReference);
        self::assertSame('inv-1', $submission->invoiceReference);

        // The session declares FA (3) and wraps the AES key for the Ministry.
        self::assertNotNull($this->openedSession);
        self::assertSame(['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], $this->openedSession['formCode']);
        $encryption = $this->openedSession['encryption'];
        self::assertIsArray($encryption);
        self::assertSame('symmetric-key', $encryption['publicKeyId']);

        // KSeF can decrypt the payload and the hashes/sizes describe plaintext and ciphertext exactly.
        $send = $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices')[0];
        $xml = $this->decryptInvoice($send);
        $body = FakeKsef::body($send);
        self::assertStringContainsString('<P_2>FV/2026/06/001</P_2>', $xml);
        self::assertSame(base64_encode(hash('sha256', $xml, true)), $body['invoiceHash']);
        self::assertSame(\strlen($xml), $body['invoiceSize']);
        self::assertIsString($body['encryptedInvoiceContent']);
        $cipher = (string) base64_decode($body['encryptedInvoiceContent'], true);
        self::assertSame(base64_encode(hash('sha256', $cipher, true)), $body['encryptedInvoiceHash']);
        self::assertSame(\strlen($cipher), $body['encryptedInvoiceSize']);
        self::assertSame($submission->invoiceHash, $body['invoiceHash']);

        // The session is closed after a one-shot send, and everything used a bearer token.
        self::assertCount(1, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/close'));
        self::assertSame('Bearer access-1', $send->getHeaderLine('Authorization'));
    }

    public function testInvoicesWithAttachmentsAreRefusedInInteractiveSessionsBeforeAnythingIsSent(): void
    {
        $this->routeSession();
        $invoice = Fixtures::builder()
            ->addLine(\B4x\Ksef\Invoice\InvoiceLine::of('Service', '1', 'szt.', '10.00', \B4x\Ksef\Invoice\VatRate::Rate23))
            ->attachment(new \B4x\Ksef\Invoice\Attachment([new \B4x\Ksef\Invoice\AttachmentBlock(['Period' => '2026-05'], 'Statement', ['text'])]))
            ->build();

        try {
            $this->client()->sendInvoice($invoice);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('only in batch sessions', $e->getMessage());
        }
        self::assertCount(0, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices'));
    }

    public function testWaitingForTheVerdictPollsUntilAccepted(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(100, 'Accepted for processing'));
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(150, 'Processing'));
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(200, 'Success', self::KSEF_NUMBER));

        $client = $this->client();
        $result = $client->waitForInvoice($client->sendInvoice(Fixtures::standardInvoice()))->assertAccepted();

        self::assertSame(self::KSEF_NUMBER, $result->ksefNumber);
        self::assertTrue($result->status->isAccepted());
        self::assertCount(3, $this->ksef->requestsTo('GET', '/sessions/sess-1/invoices/inv-1'));
    }

    public function testRejectedInvoicesRaiseWithTheKsefExplanation(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(450, 'Semantic validation error', null, ['Invalid P_2']));

        $client = $this->client();
        $result = $client->waitForInvoice($client->sendInvoice(Fixtures::standardInvoice()));

        self::assertTrue($result->status->isRejected());
        try {
            $result->assertAccepted();
            self::fail('Expected InvoiceRejectedException');
        } catch (InvoiceRejectedException $e) {
            self::assertSame(450, $e->status?->code);
            self::assertStringContainsString('Invalid P_2', $e->getMessage());
        }
    }

    public function testDuplicateStatusPointsAtTheOriginalDocument(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-2']);
        $status = $this->invoiceStatus(440, 'Duplicate invoice', null, [], ['originalKsefNumber' => self::KSEF_NUMBER, 'originalSessionReferenceNumber' => 'sess-0']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-2', 200, $status);

        $client = $this->client();
        $result = $client->waitForInvoice($client->sendInvoice(Fixtures::standardInvoice()));

        self::assertTrue($result->status->isDuplicate());
        self::assertSame(self::KSEF_NUMBER, $result->status->originalKsefNumber());
        $this->expectException(InvoiceRejectedException::class);
        $this->expectExceptionMessage('already stored as ' . self::KSEF_NUMBER);
        $result->assertAccepted();
    }

    public function testWithRecoveryDisabledAnAmbiguousFailureIsSurfacedImmediately(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => throw Http::networkError('read timeout'));

        try {
            $this->client(null, SubmissionRecoveryPolicy::disabled())->sendInvoice(Fixtures::standardInvoice());
            self::fail('Expected SubmissionOutcomeUnknownException');
        } catch (SubmissionOutcomeUnknownException $e) {
            self::assertSame('sess-1', $e->sessionReference);
            self::assertNotSame('', $e->invoiceHash);
            self::assertStringContainsString('read timeout', $e->getMessage());
        }

        self::assertCount(1, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices'));
        self::assertCount(1, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/close'), 'The session is still closed.');
    }

    public function testLostResponseIsReconciledByFindingTheDocumentInTheSession(): void
    {
        $this->routeSession();
        // The first attempt reaches KSeF but the response is lost.
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => throw Http::networkError('connection reset'));
        $this->ksef->on('GET', '/sessions/sess-1/invoices', function (RequestInterface $request) {
            $hash = FakeKsef::body($this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices')[0])['invoiceHash'];
            $mine = $this->invoiceStatus(150, 'Processing');
            $mine['invoiceHash'] = $hash;
            $mine['referenceNumber'] = 'inv-found';

            return Http::json(200, ['invoices' => [$mine]]);
        });

        $submission = $this->client()->sendInvoice(Fixtures::standardInvoice());

        self::assertSame('inv-found', $submission->invoiceReference);
        self::assertTrue($submission->recovered);
        self::assertCount(1, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices'), 'No re-send is needed when KSeF already has the document.');
    }

    public function testDocumentThatNeverArrivedIsResentAfterABackoff(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => throw Http::networkError('timeout'));
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(202, ['referenceNumber' => 'inv-1']));
        $this->ksef->json('GET', '/sessions/sess-1/invoices', 200, ['invoices' => []]);

        $submission = $this->client()->sendInvoice(Fixtures::standardInvoice());

        self::assertSame('inv-1', $submission->invoiceReference);
        self::assertTrue($submission->recovered);
        $sends = $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices');
        self::assertCount(2, $sends);
        self::assertSame(FakeKsef::body($sends[0])['invoiceHash'], FakeKsef::body($sends[1])['invoiceHash'], 'The identical document is re-sent.');
        self::assertContains(1.0, $this->sleeper->sleeps);
    }

    public function testRecoveryGivesUpAfterTheConfiguredResendsAndReportsAnUnknownOutcome(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 503, ['title' => 'Service Unavailable']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices', 200, ['invoices' => []]);

        try {
            $this->client()->sendInvoice(Fixtures::standardInvoice());
            self::fail('Expected SubmissionOutcomeUnknownException');
        } catch (SubmissionOutcomeUnknownException $e) {
            self::assertSame('sess-1', $e->sessionReference);
        }

        self::assertCount(3, $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices'), 'one attempt plus two re-sends');
        self::assertSame([1.0, 2.0], \array_slice($this->sleeper->sleeps, -2));
    }

    public function testAFailingLookupDoesNotPreventTheResend(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => throw Http::networkError());
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(202, ['referenceNumber' => 'inv-1']));
        $this->ksef->json('GET', '/sessions/sess-1/invoices', 500, ['title' => 'boom']);

        self::assertSame('inv-1', $this->client(new RetryPolicy(maxAttempts: 1))->sendInvoice(Fixtures::standardInvoice())->invoiceReference);
    }

    public function testRefusalDuringAResendIsNotMistakenForAnAmbiguousOutcome(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => throw Http::networkError());
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(400, ['title' => 'Bad Request', 'errors' => [['code' => 21405, 'description' => 'Validation error']]]));
        $this->ksef->json('GET', '/sessions/sess-1/invoices', 200, ['invoices' => []]);

        $this->expectException(ApiException::class);
        $this->client()->sendInvoice(Fixtures::standardInvoice());
    }

    public function testDuplicateAfterARecoveredSubmissionCountsAsStored(): void
    {
        $status = $this->invoiceStatus(440, 'Duplicate invoice', null, [], ['originalKsefNumber' => self::KSEF_NUMBER]);
        $invoice = \B4x\Ksef\Status\SessionInvoice::fromPayload(new \B4x\Ksef\Http\Payload($status));

        self::assertSame(self::KSEF_NUMBER, $invoice->resolvedKsefNumber());
        self::assertSame($invoice, $invoice->assertStored());
        $this->expectException(InvoiceRejectedException::class);
        $invoice->assertAccepted();
    }

    public function testWaitingUntilStoredKeepsPollingWhileThePermanentStorageDateIsMissing(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(200, 'Success', self::KSEF_NUMBER, [], [], false));
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(200, 'Success', self::KSEF_NUMBER));
        $client = $this->client();
        $submission = $client->sendInvoice(Fixtures::standardInvoice());

        $result = $client->waitForInvoice($submission, null, true);

        self::assertTrue($result->isPermanentlyStored());
        self::assertCount(2, $this->ksef->requestsTo('GET', '/sessions/sess-1/invoices/inv-1'));
    }

    public function testRateLimitedSendsAreRetriedBecauseNothingWasProcessed(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(429, ['title' => 'Too Many Requests'], ['Retry-After' => '2']));
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(202, ['referenceNumber' => 'inv-1']));

        $submission = $this->client()->sendInvoice(Fixtures::standardInvoice());

        self::assertSame('inv-1', $submission->invoiceReference);
        self::assertContains(2.0, $this->sleeper->sleeps);
    }

    public function testInvalidInvoicesNeverReachTheNetwork(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->client()->sendInvoice('<Faktura/>');
        } finally {
            self::assertSame([], $this->ksef->requests);
        }
    }

    public function testAnExpiredAccessTokenIsRefreshedBeforeTheCall(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $this->ksef->json('POST', '/auth/token/refresh', 200, ['accessToken' => ['token' => 'access-2', 'validUntil' => '2026-06-01T11:00:00+00:00']]);
        $client = $this->client();
        $client->openOnlineSession();

        $this->clock->set('2026-06-01T10:14:30+00:00');
        $client->sendInvoice(Fixtures::standardInvoice());

        self::assertSame('Bearer access-2', $this->ksef->requestsTo('POST', '/sessions/online/sess-1/invoices')[0]->getHeaderLine('Authorization'));
    }

    public function testA401AnswerTriggersOneReauthenticationAndRetry(): void
    {
        $this->routeSession();
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(401, ['title' => 'Unauthorized']));
        $this->ksef->on('POST', '/sessions/online/sess-1/invoices', static fn() => Http::json(202, ['referenceNumber' => 'inv-1']));

        $submission = $this->client()->sendInvoice(Fixtures::standardInvoice());

        self::assertSame('inv-1', $submission->invoiceReference);
        self::assertCount(2, $this->ksef->requestsTo('POST', '/auth/ksef-token'), 'tokens were discarded and re-issued');
    }

    public function testApiRejectionOfTheSendRequestPropagatesUnchanged(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 400, ['title' => 'Bad Request', 'errors' => [['code' => 21405, 'description' => 'Validation error']]]);

        try {
            $this->client()->sendInvoice(Fixtures::standardInvoice());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(21405, $e->ksefCode());
        }
    }

    public function testClosingFailureDoesNotMaskTheOriginalError(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 400, ['title' => 'Bad Request', 'errors' => [['code' => 21405, 'description' => 'Validation error']]]);
        $this->ksef->routesReset('POST', '/sessions/online/sess-1/close');
        $this->ksef->json('POST', '/sessions/online/sess-1/close', 500, ['title' => 'boom']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Validation error');
        $this->client()->sendInvoice(Fixtures::standardInvoice());
    }

    public function testSessionCannotBeUsedAfterClosingOrExpiry(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $session = $this->client()->openOnlineSession();
        $session->send(Fixtures::standardInvoice());
        $session->close();
        $session->close(); // idempotent

        $this->expectException(SessionException::class);
        $session->send(Fixtures::standardInvoice());
    }

    public function testExpiredSessionsAreRejectedLocally(): void
    {
        $this->routeSession();
        $session = $this->client()->openOnlineSession();
        $this->clock->set('2026-06-02T00:00:00+00:00');

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('expired');
        $session->send(Fixtures::standardInvoice());
    }

    public function testPollingGivesUpAfterTheConfiguredBudget(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $this->ksef->json('GET', '/sessions/sess-1/invoices/inv-1', 200, $this->invoiceStatus(150, 'Processing'));
        $client = $this->client();
        $submission = $client->sendInvoice(Fixtures::standardInvoice());

        $this->expectException(PollingTimeoutException::class);
        $client->waitForInvoice($submission, new PollingPolicy(1.0, 2.0, 2.0, 10.0));
    }

    public function testRawXmlAndPreparedDocumentsAreAccepted(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 202, ['referenceNumber' => 'inv-1']);
        $client = $this->client();
        $document = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock);

        self::assertSame($document->hash(), $client->sendInvoice($document->xml)->invoiceHash);
        self::assertSame($document->hash(), $client->sendInvoice($document)->invoiceHash);
    }

    public function testInvoiceUpoIsDownloadedAndItsHashVerified(): void
    {
        $upo = '<Potwierdzenie>signed</Potwierdzenie>';
        $this->ksef->on('GET', '/sessions/sess-1/invoices/inv-1/upo', static fn() => Http::raw(200, $upo, ['x-ms-meta-hash' => base64_encode(hash('sha256', $upo, true))]));

        $result = $this->client()->invoiceUpo(new \B4x\Ksef\Status\InvoiceSubmission('sess-1', 'inv-1', 'h'));

        self::assertSame($upo, $result->xml);
        self::assertTrue($result->verifyHash());
        self::assertSame('application/xml', $this->ksef->requestsTo('GET', '/sessions/sess-1/invoices/inv-1/upo')[0]->getHeaderLine('Accept'));
    }

    public function testTamperedUpoIsRejected(): void
    {
        $this->ksef->on('GET', '/sessions/sess-1/invoices/inv-1/upo', static fn() => Http::raw(200, '<tampered/>', ['x-ms-meta-hash' => base64_encode(hash('sha256', 'something else', true))]));

        $this->expectException(MalformedResponseException::class);
        $this->client()->invoiceUpo(new \B4x\Ksef\Status\InvoiceSubmission('sess-1', 'inv-1', 'h'));
    }

    public function testDownloadedInvoicesAreHashChecked(): void
    {
        $xml = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock)->xml;
        $this->ksef->on('GET', '/invoices/ksef/' . self::KSEF_NUMBER, static fn() => Http::raw(200, $xml, ['x-ms-meta-hash' => base64_encode(hash('sha256', $xml, true))]));

        $downloaded = $this->client()->downloadInvoice(self::KSEF_NUMBER);

        self::assertSame($xml, $downloaded->xml);
        self::assertTrue($downloaded->verifyHash());

        $this->expectException(ValidationException::class);
        $this->client()->downloadInvoice('5265877635-20260601-0100001AF629-00');
    }

    public function testDownloadWaitsWhileTheInvoiceIsNotStoredYet(): void
    {
        $xml = InvoiceDocument::fromInvoice(Fixtures::standardInvoice(), $this->clock)->xml;
        $path = '/invoices/ksef/' . self::KSEF_NUMBER;
        $this->ksef->on('GET', $path, static fn() => Http::json(406, ['title' => 'Not Acceptable']));
        $this->ksef->on('GET', $path, static fn() => Http::json(406, ['title' => 'Not Acceptable']));
        $this->ksef->on('GET', $path, static fn() => Http::raw(200, $xml, ['x-ms-meta-hash' => base64_encode(hash('sha256', $xml, true))]));
        $client = $this->client();

        $downloaded = $client->downloadInvoice(self::KSEF_NUMBER, new PollingPolicy(1.0, 2.0, 1.0, 30.0));

        self::assertSame($xml, $downloaded->xml);
        self::assertCount(3, $this->ksef->requestsTo('GET', $path));
    }

    public function testDownloadWithoutWaitSurfacesTheNotAvailableCondition(): void
    {
        $this->ksef->on('GET', '/invoices/ksef/' . self::KSEF_NUMBER, static fn() => Http::json(406, ['title' => 'Not Acceptable']));

        $this->expectException(\B4x\Ksef\Exception\InvoiceNotAvailableException::class);
        $this->client()->downloadInvoice(self::KSEF_NUMBER);
    }

    public function testInvoiceSearchSendsFiltersAndParsesMetadata(): void
    {
        $this->ksef->on('POST', '/invoices/query/metadata', fn(RequestInterface $request) => Http::json(200, [
            'hasMore' => false,
            'isTruncated' => false,
            'invoices' => [[
                'ksefNumber' => self::KSEF_NUMBER,
                'invoiceNumber' => 'FV/1',
                'issueDate' => '2026-06-01',
                'invoicingDate' => '2026-06-01T10:00:00+00:00',
                'acquisitionDate' => '2026-06-01T10:00:01+00:00',
                'permanentStorageDate' => '2026-06-01T10:00:02+00:00',
                'seller' => ['nip' => '5265877635', 'name' => 'Seller'],
                'buyer' => ['identifier' => ['type' => 'Nip', 'value' => '1234563218'], 'name' => 'Buyer'],
                'netAmount' => 100.5,
                'grossAmount' => 123.62,
                'vatAmount' => 23.12,
                'currency' => 'PLN',
                'invoicingMode' => 'Online',
                'invoiceType' => 'Vat',
                'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
                'isSelfInvoicing' => false,
                'hasAttachment' => false,
                'invoiceHash' => 'hash=',
            ]],
        ]));

        $page = $this->client()->searchInvoices(
            \B4x\Ksef\Api\InvoiceSubjectType::Buyer,
            \B4x\Ksef\Api\InvoiceDateType::PermanentStorage,
            new DateTimeImmutable('2026-05-01T00:00:00+02:00'),
            new DateTimeImmutable('2026-06-01T00:00:00Z'),
        );

        $request = $this->ksef->requestsTo('POST', '/invoices/query/metadata')[0];
        self::assertSame(['subjectType' => 'Subject2', 'dateRange' => ['dateType' => 'PermanentStorage', 'from' => '2026-04-30T22:00:00Z', 'to' => '2026-06-01T00:00:00Z']], FakeKsef::body($request));
        self::assertSame('pageOffset=0&pageSize=100&sortOrder=Asc', $request->getUri()->getQuery());
        self::assertCount(1, $page->invoices);
        self::assertSame('100.50', $page->invoices[0]->netAmount->toString(2));
        self::assertSame('Nip', $page->invoices[0]->buyerIdentifierType);
    }

    public function testBuilderListsEverythingThatIsMissing(): void
    {
        try {
            KsefClient::builder()->build();
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('environment() or baseUrl()', $e->getMessage());
            self::assertStringContainsString('httpClient()', $e->getMessage());
            self::assertStringContainsString('context()', $e->getMessage());
            self::assertStringContainsString('credentials()', $e->getMessage());
        }
    }

    public function testSecretsNeverAppearInExceptionMessages(): void
    {
        $this->routeSession();
        $this->ksef->json('POST', '/sessions/online/sess-1/invoices', 400, ['title' => 'Bad Request']);

        try {
            $this->client()->sendInvoice(Fixtures::standardInvoice());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertStringNotContainsString('secret-ksef-token', (string) $e);
            self::assertStringNotContainsString('access-1', $e->getMessage());
        }
    }

    /**
     * @param list<string> $details
     * @param array<string, mixed> $extensions
     *
     * @return array<string, mixed>
     */
    private function invoiceStatus(int $code, string $description, ?string $ksefNumber = null, array $details = [], array $extensions = [], bool $stored = true): array
    {
        return [
            'ordinalNumber' => 1,
            'referenceNumber' => 'inv-1',
            'invoiceHash' => 'hash=',
            'invoicingDate' => '2026-06-01T10:00:00+00:00',
            'status' => ['code' => $code, 'description' => $description, 'details' => $details] + ($extensions !== [] ? ['extensions' => $extensions] : []),
        ] + ($ksefNumber !== null ? ['ksefNumber' => $ksefNumber, 'acquisitionDate' => '2026-06-01T10:00:05+00:00'] + ($stored ? ['permanentStorageDate' => '2026-06-01T10:00:06+00:00'] : []) : []);
    }
}
