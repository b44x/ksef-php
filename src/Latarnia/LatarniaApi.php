<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\Payload;
use B4x\Ksef\Http\Transport;

/**
 * Client of the Ministry's public availability service ("Latarnia"): no authentication, separate hosts from the API.
 *
 * @internal use KsefClient::availability() and KsefClient::availabilityMessages()
 */
final class LatarniaApi
{
    public function __construct(private readonly Transport $transport) {}

    public function status(): AvailabilityReport
    {
        $data = new Payload($this->transport->send(ApiRequest::get('/status'))->json());
        $status = AvailabilityStatus::tryFrom($data->string('status'))
            ?? throw new MalformedResponseException('The availability service returned an unknown status.');

        return new AvailabilityReport($status, $this->messageList($data));
    }

    /** @return list<AvailabilityMessage> */
    public function messages(): array
    {
        $json = $this->transport->send(ApiRequest::get('/messages'))->json();
        if (!array_is_list($json)) {
            throw new MalformedResponseException('The availability service returned messages in an unexpected structure.');
        }

        return $this->messageList(new Payload(['messages' => $json]));
    }

    /** @return list<AvailabilityMessage> */
    private function messageList(Payload $data): array
    {
        return array_map(self::message(...), $data->objects('messages'));
    }

    private static function message(Payload $m): AvailabilityMessage
    {
        return new AvailabilityMessage(
            $m->string('id'),
            $m->int('eventId'),
            MessageCategory::tryFrom($m->string('category')) ?? throw new MalformedResponseException('The availability service returned an unknown message category.'),
            MessageType::tryFrom($m->string('type')) ?? throw new MalformedResponseException('The availability service returned an unknown message type.'),
            $m->string('title'),
            $m->string('text'),
            $m->date('start'),
            $m->optionalDate('end'),
            $m->int('version'),
            $m->date('published'),
        );
    }
}
