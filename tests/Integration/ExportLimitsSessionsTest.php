<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Api\InvoiceDateType;
use B4x\Ksef\Api\InvoiceSubjectType;
use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Http;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;

final class ExportLimitsSessionsTest extends KsefTestCase
{
    public function testExportDownloadsVerifiesDecryptsAndConcatenatesTheParts(): void
    {
        $zip = str_repeat('PK-fake-zip-content-', 20);
        $halves = [substr($zip, 0, 150), substr($zip, 150)];
        $cipherParts = [];
        $this->ksef->on('POST', '/invoices/exports', function (RequestInterface $request) use ($halves, &$cipherParts) {
            $body = FakeKsef::body($request);
            self::assertSame(['subjectType' => 'Subject2', 'dateRange' => ['dateType' => 'PermanentStorage', 'from' => '2026-05-01T00:00:00Z']], $body['filters']);
            $encryption = $body['encryption'];
            self::assertIsArray($encryption);
            $aes = $this->unwrapSessionKey($encryption);
            foreach ($halves as $i => $plain) {
                $cipherParts[$i + 1] = (string) openssl_encrypt($plain, 'aes-256-cbc', $aes['key'], OPENSSL_RAW_DATA, $aes['iv']);
            }

            return Http::json(201, ['referenceNumber' => 'exp-1']);
        });
        $this->ksef->on('GET', '/invoices/exports/exp-1', function () use ($halves, &$cipherParts) {
            $parts = [];
            foreach ($halves as $i => $plain) {
                $parts[] = [
                    'ordinalNumber' => $i + 1, 'partName' => 'p' . ($i + 1), 'method' => 'GET', 'url' => 'https://files.example.test/part/' . ($i + 1),
                    'partSize' => \strlen($plain), 'partHash' => base64_encode(hash('sha256', $plain, true)),
                    'encryptedPartSize' => \strlen($cipherParts[$i + 1]), 'encryptedPartHash' => base64_encode(hash('sha256', $cipherParts[$i + 1], true)),
                    'expirationDate' => '2026-06-08T10:00:00+00:00',
                ];
            }

            return Http::json(200, ['status' => ['code' => 200, 'description' => 'ok'], 'package' => ['invoiceCount' => 7, 'size' => 300, 'isTruncated' => true, 'lastPermanentStorageDate' => '2026-05-20T10:00:00+00:00', 'parts' => $parts, 'compressionType' => 'Zip']]);
        });
        foreach ([1, 2] as $i) {
            $this->ksef->on('GET', '/part/' . $i, function () use (&$cipherParts, $i) {
                return Http::raw(200, $cipherParts[$i]);
            });
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'ksef-export-test-');

        $package = $this->client()->exportInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, new DateTimeImmutable('2026-05-01T00:00:00Z'), null, $path);

        self::assertSame($zip, file_get_contents($path));
        self::assertSame(7, $package->invoiceCount);
        self::assertTrue($package->isTruncated);
        self::assertSame('2026-05-20', $package->continueFrom?->format('Y-m-d'));
        self::assertFalse($this->ksef->requestsTo('GET', '/part/1')[0]->hasHeader('Authorization'), 'pre-signed links get no token');
        unlink($path);
    }

    public function testCorruptPartsAreDetectedAndNoFileIsLeftBehind(): void
    {
        $this->ksef->json('POST', '/invoices/exports', 201, ['referenceNumber' => 'exp-1']);
        $this->ksef->json('GET', '/invoices/exports/exp-1', 200, ['status' => ['code' => 200, 'description' => 'ok'], 'package' => ['invoiceCount' => 1, 'size' => 1, 'isTruncated' => false, 'compressionType' => 'Zip', 'parts' => [[
            'ordinalNumber' => 1, 'partName' => 'p', 'method' => 'GET', 'url' => 'https://files.example.test/part/1', 'partSize' => 3, 'partHash' => 'x', 'encryptedPartSize' => 16, 'encryptedPartHash' => base64_encode(hash('sha256', 'expected', true)), 'expirationDate' => '2026-06-08T10:00:00+00:00',
        ]]]]);
        $this->ksef->on('GET', '/part/1', static fn() => Http::raw(200, 'tampered'));
        $path = (string) tempnam(sys_get_temp_dir(), 'ksef-export-test-');

        try {
            $this->client()->exportInvoices(InvoiceSubjectType::Seller, InvoiceDateType::Issue, new DateTimeImmutable('2026-05-01'), null, $path);
            self::fail('Expected MalformedResponseException');
        } catch (MalformedResponseException) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testFailedExportsExplainWhy(): void
    {
        $this->ksef->json('POST', '/invoices/exports', 201, ['referenceNumber' => 'exp-1']);
        $this->ksef->json('GET', '/invoices/exports/exp-1', 200, ['status' => ['code' => 420, 'description' => 'Range outside data', 'details' => ['from is later than the high-water mark']]]);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('high-water mark');
        $this->client()->exportInvoices(InvoiceSubjectType::Seller, InvoiceDateType::PermanentStorage, new DateTimeImmutable('2026-05-01'), null, sys_get_temp_dir() . '/never-written.zip');
    }

    public function testLimitsAndRateLimitsAreParsed(): void
    {
        $session = ['maxInvoiceSizeInMB' => 1, 'maxInvoiceWithAttachmentSizeInMB' => 3, 'maxInvoices' => 10000];
        $this->ksef->json('GET', '/limits/context', 200, ['onlineSession' => $session, 'batchSession' => ['maxInvoices' => 500] + $session, 'collectiveIdentifier' => ['maxInvoices' => 500]]);
        $this->ksef->json('GET', '/rate-limits', 200, ['invoiceSend' => ['perSecond' => 10, 'perMinute' => 30, 'perHour' => 180], 'global' => ['perSecond' => 100, 'perMinute' => 300, 'perHour' => 1000]]);
        $client = $this->client();

        $limits = $client->contextLimits();
        $rates = $client->rateLimits();

        self::assertSame(10000, $limits->onlineSession->maxInvoices);
        self::assertSame(500, $limits->batchSession->maxInvoices);
        self::assertSame(3, $limits->onlineSession->maxInvoiceWithAttachmentSizeMb);
        self::assertSame(30, $rates['invoiceSend']->perMinute);
        self::assertSame(['invoiceSend', 'global'], array_keys($rates));
    }

    public function testAuthSessionsAreListedAndRevoked(): void
    {
        $this->ksef->json('GET', '/auth/sessions', 200, ['continuationToken' => 'next', 'items' => [[
            'referenceNumber' => 'auth-1', 'startDate' => '2026-06-01T10:00:00+00:00', 'authenticationMethod' => 'Token', 'authenticationMethodInfo' => [],
            'status' => ['code' => 200, 'description' => 'ok'], 'isCurrent' => true, 'refreshTokenValidUntil' => '2026-06-08T10:00:00+00:00',
        ]]]);
        $this->ksef->json('DELETE', '/auth/sessions/auth-1', 204, []);
        $this->ksef->json('DELETE', '/auth/sessions/current', 204, []);
        $client = $this->client();

        $page = $client->authSessions();
        $client->revokeAuthSession('auth-1');
        $client->revokeAuthSession();

        self::assertTrue($page->items[0]->isCurrent);
        self::assertSame('next', $page->continuationToken);
        self::assertCount(1, $this->ksef->requestsTo('DELETE', '/auth/sessions/auth-1'));
        self::assertCount(1, $this->ksef->requestsTo('DELETE', '/auth/sessions/current'));
    }
}
