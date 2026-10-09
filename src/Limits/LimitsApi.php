<?php

declare(strict_types=1);

namespace B4x\Ksef\Limits;

use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\Payload;

/** Typed wrapper over `/limits/*` and `/rate-limits`. */
final class LimitsApi
{
    public function __construct(private readonly AuthorizedClient $client) {}

    public function context(): ContextLimits
    {
        $data = new Payload($this->client->send(ApiRequest::get('/limits/context'))->json());

        return new ContextLimits($this->session($data->object('onlineSession')), $this->session($data->object('batchSession')));
    }

    /**
     * Allowed request rates keyed by endpoint group (for example `invoiceSend`, `invoiceStatus`, `global`).
     *
     * @return array<string, RateLimit>
     */
    public function rates(): array
    {
        $payload = $this->client->send(ApiRequest::get('/rate-limits'))->json();

        $rates = [];
        foreach (array_keys($payload) as $group) {
            $values = (new Payload($payload))->optionalObject((string) $group);
            if ($values !== null) {
                $rates[(string) $group] = new RateLimit($values->int('perSecond'), $values->int('perMinute'), $values->int('perHour'));
            }
        }

        return $rates;
    }

    private function session(Payload $data): SessionLimits
    {
        return new SessionLimits($data->int('maxInvoices'), $data->int('maxInvoiceSizeInMB'), $data->int('maxInvoiceWithAttachmentSizeInMB'));
    }
}
