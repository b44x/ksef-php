<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** An administrator of a subordinate unit or entity, as listed by KSeF. */
final readonly class SubunitPermission
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $description,
        public DateTimeImmutable $startDate,
        public PermissionIdentifier $authorized,
        public PermissionIdentifier $subunit,
        public ?string $subunitName,
    ) {}

    /** @internal parses a KSeF response */
    public static function fromPayload(Payload $data): self
    {
        return new self(
            $data->string('id'),
            $data->string('permissionScope'),
            $data->string('description'),
            $data->date('startDate'),
            PermissionIdentifier::fromPayload($data->object('authorizedIdentifier')),
            PermissionIdentifier::fromPayload($data->object('subunitIdentifier')),
            $data->optionalString('subunitName'),
        );
    }
}
