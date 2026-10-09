<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** An administrator or representative of an EU entity, as listed by KSeF. */
final readonly class EuEntityPermission
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $description,
        public DateTimeImmutable $startDate,
        public string $vatUeIdentifier,
        public string $euEntityName,
        public string $authorizedFingerprint,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        return new self(
            $data->string('id'),
            $data->string('permissionScope'),
            $data->string('description'),
            $data->date('startDate'),
            $data->string('vatUeIdentifier'),
            $data->string('euEntityName'),
            $data->string('authorizedFingerprintIdentifier'),
        );
    }
}
