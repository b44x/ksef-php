<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** A permission as listed by KSeF. {@see self::$id} is what {@see \B4x\Ksef\KsefClient::revokePermission()} needs. */
final readonly class PermissionGrant
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $description,
        public DateTimeImmutable $startDate,
        public bool $canDelegate,
        public ?string $state,
        public ?string $holderType,
        public ?string $holder,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $holder = $data->optionalObject('authorizedIdentifier');

        return new self(
            $data->string('id'),
            $data->string('permissionScope'),
            $data->string('description'),
            $data->date('startDate'),
            $data->bool('canDelegate'),
            $data->optionalString('permissionState'),
            $holder?->string('type'),
            $holder?->string('value'),
        );
    }

    public function isActive(): bool
    {
        return $this->state === null || $this->state === 'Active';
    }
}
