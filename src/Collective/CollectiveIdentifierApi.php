<?php

declare(strict_types=1);

namespace B4x\Ksef\Collective;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Support\Constraint;
use B4x\Ksef\Support\KsefNumber;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Typed wrapper over `/collective-identifiers/*`.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class CollectiveIdentifierApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    /**
     * @param non-empty-list<CollectiveInvoice> $invoices invoices of one seller, up to the limit of the context (500 by default)
     *
     * @return string the collective identifier, for example "1111111111-IZ202607-65ED02180000-E7"
     */
    public function generate(array $invoices): string
    {
        if (\count($invoices) < 2) {
            throw new ValidationException('A collective identifier needs at least two invoices.');
        }
        $items = [];
        foreach ($invoices as $invoice) {
            $error = KsefNumber::validate($invoice->ksefNumber);
            if ($error !== null) {
                throw new ValidationException(\sprintf('"%s" is not a valid KSeF number (%s).', $invoice->ksefNumber, $error));
            }
            $item = ['ksefNumber' => $invoice->ksefNumber];
            if ($invoice->payment !== null) {
                $item['payment'] = ['amount' => (float) $invoice->payment->amount->toString(2), 'currency' => $invoice->payment->currency];
            }
            if ($invoice->description !== null) {
                Constraint::length('invoice description', $invoice->description, 1, 512);
                $item['description'] = $invoice->description;
            }
            $items[] = $item;
        }

        // The identifier is created server side: a blind re-send would create a second one, so only 429 is retried.
        $response = $this->client->send(ApiRequest::post('/collective-identifiers', ['invoices' => $items], null, RetryMode::RateLimitOnly));

        return (new Payload($response->json()))->string('collectiveIdentifierNumber');
    }

    /**
     * @param DateTimeInterface $from creation date from; the range may span at most 100 days
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifier>
     */
    public function query(DateTimeInterface $from, DateTimeInterface $to, ?string $number = null, ?bool $createdInCurrentContext = null, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        Constraint::pageSize($pageSize, 10, 200);
        $body = ['dateCreatedFrom' => self::dateTime($from), 'dateCreatedTo' => self::dateTime($to)];
        if ($number !== null) {
            $body['collectiveIdentifierNumber'] = $number;
        }
        if ($createdInCurrentContext !== null) {
            $body['createdInCurrentContext'] = $createdInCurrentContext;
        }

        $data = $this->page(ApiRequest::post('/collective-identifiers/query', $body, null, RetryMode::Safe, ['pageSize' => $pageSize]), $continuationToken);

        return new CollectiveIdentifierPage(array_map(CollectiveIdentifier::fromPayload(...), $data->objects('collectiveIdentifiers')), $data->optionalString('continuationToken'));
    }

    /**
     * @param non-empty-list<string> $numbers up to 10 collective identifiers
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifierInvoice>
     */
    public function invoices(array $numbers, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        Constraint::pageSize($pageSize, 10, 500);
        if (\count($numbers) > 10) {
            throw new ValidationException('At most 10 collective identifiers can be queried at once.');
        }
        $data = $this->page(ApiRequest::post('/collective-identifiers/invoices', ['collectiveIdentifierNumbers' => array_values($numbers)], null, RetryMode::Safe, ['pageSize' => $pageSize]), $continuationToken);

        return new CollectiveIdentifierPage(array_map(CollectiveIdentifierInvoice::fromPayload(...), $data->objects('invoices')), $data->optionalString('continuationToken'));
    }

    /**
     * Collective identifiers an invoice belongs to.
     *
     * @return CollectiveIdentifierPage<CollectiveIdentifier>
     */
    public function ofInvoice(string $ksefNumber, ?string $continuationToken = null, int $pageSize = 10): CollectiveIdentifierPage
    {
        Constraint::pageSize($pageSize, 10, 200);
        $error = KsefNumber::validate($ksefNumber);
        if ($error !== null) {
            throw new ValidationException(\sprintf('"%s" is not a valid KSeF number (%s).', $ksefNumber, $error));
        }
        $data = $this->page(ApiRequest::get('/collective-identifiers/ksef/' . rawurlencode($ksefNumber), null, ['pageSize' => $pageSize]), $continuationToken);

        return new CollectiveIdentifierPage(array_map(CollectiveIdentifier::fromPayload(...), $data->objects('collectiveIdentifiers')), $data->optionalString('continuationToken'));
    }

    private function page(ApiRequest $request, ?string $continuationToken): Payload
    {
        if ($continuationToken !== null) {
            $request = $request->withHeaders(['x-continuation-token' => $continuationToken]);
        }

        return new Payload($this->client->send($request)->json());
    }

    private static function dateTime(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
