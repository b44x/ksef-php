<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Integration;

use B4x\Ksef\Exception\SessionException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\Tests\Support\FakeKsef;
use B4x\Ksef\Tests\Support\Fixtures;
use B4x\Ksef\Tests\Support\Http;
use Psr\Http\Message\RequestInterface;
use ZipArchive;

final class BatchFlowTest extends KsefTestCase
{
    /** @var array<int, string> uploaded ciphertext per part */
    private array $uploaded = [];

    public function testBatchIsZippedSplitEncryptedUploadedAndClosed(): void
    {
        $this->routeBatch(parts: 3);
        $documents = $this->invoices(40);

        $submission = $this->client()->sendBatch($documents, 4_000);

        self::assertSame('batch-1', $submission->sessionReference);
        self::assertCount(40, $submission->invoiceHashes);
        self::assertSame($documents[0]->hash(), $submission->invoiceHashes[0]);

        // The session request declares the archive and every part exactly as it was uploaded.
        $open = FakeKsef::body($this->ksef->requestsTo('POST', '/sessions/batch')[0]);
        self::assertSame(['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], $open['formCode']);
        $batchFile = $open['batchFile'];
        self::assertIsArray($batchFile);
        $parts = $batchFile['fileParts'];
        self::assertIsArray($parts);
        self::assertGreaterThan(1, \count($parts));
        foreach ($parts as $part) {
            self::assertIsArray($part);
            self::assertIsInt($part['ordinalNumber']);
            $cipher = $this->uploaded[$part['ordinalNumber']];
            self::assertSame(\strlen($cipher), $part['fileSize']);
            self::assertSame(base64_encode(hash('sha256', $cipher, true)), $part['fileHash']);
        }

        // Decrypting and concatenating the parts yields the declared ZIP with all invoices.
        $zip = '';
        ksort($this->uploaded);
        foreach ($this->uploaded as $cipher) {
            $encryption = $open['encryption'];
            self::assertIsArray($encryption);
            $zip .= $this->decryptWithSessionKey($encryption, $cipher);
        }
        self::assertSame($batchFile['fileSize'], \strlen($zip));
        self::assertSame($batchFile['fileHash'], base64_encode(hash('sha256', $zip, true)));
        $path = tempnam(sys_get_temp_dir(), 'ksef-test-');
        self::assertIsString($path);
        file_put_contents($path, $zip);
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));
        self::assertSame(40, $archive->numFiles);
        self::assertSame($documents[7]->xml, $archive->getFromName('invoice_00008.xml'));
        $archive->close();
        unlink($path);

        self::assertCount(1, $this->ksef->requestsTo('POST', '/sessions/batch/batch-1/close'));
    }

    public function testPartsAreUploadedWithoutCredentialsAndWithTheHeadersKsefPrescribed(): void
    {
        $this->routeBatch(parts: 1);

        $this->client()->sendBatch($this->invoices(2));

        $upload = $this->ksef->requestsTo('PUT', '/upload/1')[0];
        self::assertFalse($upload->hasHeader('Authorization'));
        self::assertSame('BlockBlob', $upload->getHeaderLine('x-ms-blob-type'));
        self::assertSame('https://upload.example.test/upload/1?sig=abc', (string) $upload->getUri());
    }

    public function testResultsAreReadPerInvoice(): void
    {
        $this->routeBatch(parts: 1);
        $this->ksef->json('GET', '/sessions/batch-1', 200, [
            'status' => ['code' => 200, 'description' => 'ok'],
            'dateCreated' => '2026-06-01T10:00:00+00:00',
            'dateUpdated' => '2026-06-01T10:00:30+00:00',
            'invoiceCount' => 2,
            'successfulInvoiceCount' => 2,
            'failedInvoiceCount' => 0,
        ]);
        $client = $this->client();
        $submission = $client->sendBatch($this->invoices(2));

        $status = $client->waitForSession($submission->sessionReference);

        self::assertTrue($status->isSuccessful());
        self::assertSame(2, $status->successfulInvoiceCount);
    }

    public function testPackagingProblemsAreReportedBeforeAnythingIsSent(): void
    {
        try {
            $this->client()->sendBatch([]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('at least one invoice', $e->getMessage());
        }
        self::assertSame([], $this->ksef->requestsTo('POST', '/sessions/batch'));
    }

    public function testMismatchingUploadSlotsAreRefusedBeforeUploading(): void
    {
        $this->routeBatch(parts: 1, announcedSlots: 2);

        $this->expectException(SessionException::class);
        try {
            $this->client()->sendBatch($this->invoices(2));
        } finally {
            self::assertSame([], $this->uploaded);
        }
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $this->routeBatch(parts: 1);
        $before = $this->tempFiles();

        $this->client()->sendBatch($this->invoices(3));

        self::assertSame(\count($before), \count($this->tempFiles()));
    }

    /** @return list<string> */
    private function tempFiles(): array
    {
        $files = glob(sys_get_temp_dir() . '/ksef-batch-*');

        return $files === false ? [] : $files;
    }

    /**
     * @return list<InvoiceDocument>
     */
    private function invoices(int $count): array
    {
        $documents = [];
        for ($i = 1; $i <= $count; ++$i) {
            $documents[] = InvoiceDocument::fromInvoice(
                Fixtures::builder()->number(\sprintf('B/%04d', $i))->addLine(InvoiceLine::of('Item ' . $i . ' ' . bin2hex(random_bytes(40)), '1', 'szt.', '10.00', VatRate::Rate23))->build(),
                $this->clock,
            );
        }

        return $documents;
    }

    private function routeBatch(int $parts, ?int $announcedSlots = null): void
    {
        $slots = $announcedSlots ?? 60;
        $this->ksef->on('POST', '/sessions/batch', function (RequestInterface $request) use ($slots) {
            $this->openedSession = FakeKsef::body($request);
            $declared = $this->openedSession['batchFile'];
            self::assertIsArray($declared);
            $count = \is_array($declared['fileParts'] ?? null) ? \count($declared['fileParts']) : 0;
            $uploads = [];
            for ($i = 1; $i <= ($slots === 60 ? $count : $slots); ++$i) {
                $uploads[] = ['ordinalNumber' => $i, 'method' => 'PUT', 'url' => 'https://upload.example.test/upload/' . $i . '?sig=abc', 'headers' => ['x-ms-blob-type' => 'BlockBlob']];
            }

            return Http::json(201, ['referenceNumber' => 'batch-1', 'partUploadRequests' => $uploads]);
        });
        for ($i = 1; $i <= 60; ++$i) {
            $this->ksef->on('PUT', '/upload/' . $i, function (RequestInterface $request) use ($i) {
                $this->uploaded[$i] = (string) $request->getBody();

                return Http::raw(201, '');
            });
        }
        $this->ksef->json('POST', '/sessions/batch/batch-1/close', 204, []);
    }
}
