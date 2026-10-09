<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Collective\CollectiveInvoice;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\Money;
use B4x\Ksef\Tests\Support\FakeKsef;
use DateTimeImmutable;

final class CollectiveAndPeppolTest extends KsefTestCase
{
    private const KSEF_NUMBER = '5265877635-20250826-0100001AF629-AF';

    public function testACollectiveIdentifierIsCreatedFromInvoicesWithTheirPayments(): void
    {
        $this->ksef->json('POST', '/collective-identifiers', 201, ['collectiveIdentifierNumber' => '5265877635-IZ202606-65ED02180000-E7']);

        $number = $this->client()->createCollectiveIdentifier([new CollectiveInvoice(self::KSEF_NUMBER, Money::pln('123.45'), 'March')]);

        self::assertSame('5265877635-IZ202606-65ED02180000-E7', $number);
        self::assertSame(
            ['invoices' => [['ksefNumber' => self::KSEF_NUMBER, 'payment' => ['amount' => 123.45, 'currency' => 'PLN'], 'description' => 'March']]],
            FakeKsef::body($this->ksef->requestsTo('POST', '/collective-identifiers')[0]),
        );
    }

    public function testListingFollowsContinuationTokens(): void
    {
        $this->ksef->json('POST', '/collective-identifiers/query', 200, ['continuationToken' => 'next', 'collectiveIdentifiers' => [
            ['collectiveIdentifierNumber' => 'X-1', 'dateCreated' => '2026-06-01T10:00:00+00:00', 'invoiceCount' => 3, 'createdInCurrentContext' => true],
        ]]);
        $this->ksef->json('POST', '/collective-identifiers/invoices', 200, ['invoices' => [
            ['collectiveIdentifierNumber' => 'X-1', 'ksefNumber' => self::KSEF_NUMBER, 'detailsHidden' => false, 'payment' => ['amount' => 10.5, 'currency' => 'PLN'], 'description' => 'd'],
            ['collectiveIdentifierNumber' => 'X-1', 'ksefNumber' => self::KSEF_NUMBER, 'detailsHidden' => true],
        ]]);
        $this->ksef->json('GET', '/collective-identifiers/ksef/' . self::KSEF_NUMBER, 200, ['collectiveIdentifiers' => [
            ['collectiveIdentifierNumber' => 'X-1', 'dateCreated' => '2026-06-01T10:00:00+00:00', 'createdInCurrentContext' => false],
        ]]);
        $client = $this->client();

        $page = $client->collectiveIdentifiers(new DateTimeImmutable('2026-05-01'), new DateTimeImmutable('2026-06-30'), createdInCurrentContext: true, continuationToken: 'prev');
        $invoices = $client->collectiveIdentifierInvoices(['X-1']);
        $of = $client->collectiveIdentifiersOf(self::KSEF_NUMBER);

        self::assertTrue($page->hasMore());
        self::assertSame(3, $page->items[0]->invoiceCount);
        $request = $this->ksef->requestsTo('POST', '/collective-identifiers/query')[0];
        self::assertSame('prev', $request->getHeaderLine('x-continuation-token'));
        self::assertSame(['dateCreatedFrom' => '2026-05-01T00:00:00Z', 'dateCreatedTo' => '2026-06-30T00:00:00Z', 'createdInCurrentContext' => true], FakeKsef::body($request));
        self::assertSame('10.50', $invoices->items[0]->paymentAmount);
        self::assertTrue($invoices->items[1]->detailsHidden);
        self::assertNull($invoices->items[1]->paymentAmount);
        self::assertNull($of->items[0]->invoiceCount);
        self::assertFalse($of->hasMore());
    }

    public function testInputIsValidatedLocally(): void
    {
        $this->expectException(ValidationException::class);
        $this->client()->createCollectiveIdentifier([new CollectiveInvoice('not-a-ksef-number')]);
    }

    public function testPeppolProvidersAndSubjectLimits(): void
    {
        $this->ksef->json('GET', '/peppol/query', 200, ['hasMore' => false, 'peppolProviders' => [['id' => 'P-1', 'name' => 'Provider', 'dateCreated' => '2026-01-01T00:00:00+00:00']]]);
        $this->ksef->json('GET', '/limits/subject', 200, ['enrollment' => ['maxEnrollments' => 12], 'certificate' => ['maxCertificates' => 6]]);
        $client = $this->client();

        $providers = $client->peppolProviders();
        $limits = $client->subjectLimits();

        self::assertSame('Provider', $providers['providers'][0]->name);
        self::assertSame(12, $limits->maxEnrollments);
        self::assertSame(6, $limits->maxCertificates);
    }
}
