<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;

/** A typed identifier as KSeF reports it (for example type "Nip", value "5265877635"). */
final readonly class PermissionIdentifier
{
    public function __construct(public string $type, public string $value) {}

    public static function fromPayload(Payload $data): self
    {
        return new self($data->string('type'), $data->optionalString('value') ?? '');
    }

    public static function optional(Payload $data, string $key): ?self
    {
        $object = $data->optionalObject($key);

        return $object === null ? null : self::fromPayload($object);
    }
}
