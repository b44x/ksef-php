<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Status\OpenedBatch;
use B4x\Ksef\Status\OpenedSession;
use B4x\Ksef\Status\PartUpload;
use B4x\Ksef\Status\SessionInvoice;
use B4x\Ksef\Status\SessionInvoicesPage;
use B4x\Ksef\Status\SessionStatus;
use B4x\Ksef\Status\Upo;

/**
 * Typed wrapper over the interactive-session and status endpoints. No flow logic lives here.
 */
final class SessionApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @param array{encryptedSymmetricKey: string, initializationVector: string, publicKeyId: string} $encryption
     */
    public function open(FormCode $formCode, array $encryption): OpenedSession
    {
        $response = $this->client->send(ApiRequest::post('/sessions/online', ['formCode' => $formCode->toArray(), 'encryption' => $encryption], null, RetryMode::RateLimitOnly));
        $data = new Payload($response->json());

        return new OpenedSession($data->string('referenceNumber'), $data->date('validUntil'));
    }

    /**
     * @param array{encryptedSymmetricKey: string, initializationVector: string, publicKeyId: string} $encryption
     * @param array{fileSize: int, fileHash: string, fileParts: list<array{ordinalNumber: int, fileSize: int, fileHash: string}>} $batchFile
     */
    public function openBatch(FormCode $formCode, array $encryption, array $batchFile): OpenedBatch
    {
        $response = $this->client->send(ApiRequest::post('/sessions/batch', ['formCode' => $formCode->toArray(), 'batchFile' => $batchFile, 'encryption' => $encryption], null, RetryMode::RateLimitOnly));
        $data = new Payload($response->json());

        $uploads = [];
        foreach ($data->objects('partUploadRequests') as $upload) {
            $headers = [];
            foreach ($upload->map('headers') as $name => $value) {
                if (\is_string($value)) {
                    $headers[$name] = $value;
                }
            }
            $uploads[] = new PartUpload($upload->int('ordinalNumber'), $upload->string('method'), $upload->string('url'), $headers);
        }

        return new OpenedBatch($data->string('referenceNumber'), $uploads);
    }

    public function closeBatch(string $sessionReference): void
    {
        $this->client->send(ApiRequest::post('/sessions/batch/' . rawurlencode($sessionReference) . '/close', null, null, RetryMode::RateLimitOnly));
    }

    /**
     * @param array<string, mixed> $payload the `SendInvoiceRequest` body
     *
     * @return string the invoice reference number
     */
    public function sendInvoice(string $sessionReference, array $payload): string
    {
        $response = $this->client->send(ApiRequest::post('/sessions/online/' . rawurlencode($sessionReference) . '/invoices', $payload, null, RetryMode::RateLimitOnly));

        return (new Payload($response->json()))->string('referenceNumber');
    }

    public function close(string $sessionReference): void
    {
        $this->client->send(ApiRequest::post('/sessions/online/' . rawurlencode($sessionReference) . '/close', null, null, RetryMode::RateLimitOnly));
    }

    public function status(string $sessionReference): SessionStatus
    {
        return SessionStatus::fromPayload(new Payload($this->client->send(ApiRequest::get('/sessions/' . rawurlencode($sessionReference)))->json()));
    }

    public function invoices(string $sessionReference, ?string $continuationToken = null, int $pageSize = 100): SessionInvoicesPage
    {
        return $this->invoicePage('/sessions/' . rawurlencode($sessionReference) . '/invoices', $continuationToken, $pageSize);
    }

    /**
     * Searches the session's invoices for the one with the given content hash.
     */
    public function findInvoiceByHash(string $sessionReference, string $invoiceHash): ?SessionInvoice
    {
        $token = null;
        do {
            $page = $this->invoices($sessionReference, $token);
            foreach ($page->invoices as $invoice) {
                if ($invoice->invoiceHash === $invoiceHash) {
                    return $invoice;
                }
            }
            $token = $page->continuationToken;
        } while ($token !== null);

        return null;
    }

    public function failedInvoices(string $sessionReference, ?string $continuationToken = null, int $pageSize = 100): SessionInvoicesPage
    {
        return $this->invoicePage('/sessions/' . rawurlencode($sessionReference) . '/invoices/failed', $continuationToken, $pageSize);
    }

    public function invoice(string $sessionReference, string $invoiceReference): SessionInvoice
    {
        $response = $this->client->send(ApiRequest::get('/sessions/' . rawurlencode($sessionReference) . '/invoices/' . rawurlencode($invoiceReference)));

        return SessionInvoice::fromPayload(new Payload($response->json()));
    }

    public function invoiceUpo(string $sessionReference, string $invoiceReference): Upo
    {
        return $this->upo('/sessions/' . rawurlencode($sessionReference) . '/invoices/' . rawurlencode($invoiceReference) . '/upo');
    }

    public function invoiceUpoByKsefNumber(string $sessionReference, string $ksefNumber): Upo
    {
        return $this->upo('/sessions/' . rawurlencode($sessionReference) . '/invoices/ksef/' . rawurlencode($ksefNumber) . '/upo');
    }

    public function sessionUpo(string $sessionReference, string $upoReference): Upo
    {
        return $this->upo('/sessions/' . rawurlencode($sessionReference) . '/upo/' . rawurlencode($upoReference));
    }

    private function invoicePage(string $path, ?string $continuationToken, int $pageSize): SessionInvoicesPage
    {
        $request = ApiRequest::get($path, null, ['pageSize' => $pageSize]);
        if ($continuationToken !== null) {
            $request = $request->withHeaders(['x-continuation-token' => $continuationToken]);
        }

        $data = new Payload($this->client->send($request)->json());
        $invoices = array_map(SessionInvoice::fromPayload(...), $data->objects('invoices'));

        return new SessionInvoicesPage($invoices, $data->optionalString('continuationToken'));
    }

    private function upo(string $path): Upo
    {
        $response = $this->client->send(ApiRequest::get($path, null, [], 'application/xml'));
        $hash = $response->header('x-ms-meta-hash');
        if ($hash === null) {
            throw new MalformedResponseException('KSeF did not send the UPO hash header (x-ms-meta-hash).');
        }

        $upo = new Upo($response->body, $hash);
        if (!$upo->verifyHash()) {
            throw new MalformedResponseException('The downloaded UPO does not match the hash announced by KSeF.');
        }

        return $upo;
    }
}
