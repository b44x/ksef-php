<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** An entity-level authorisation as listed by KSeF; {@see self::$id} is what `revokeAuthorization()` needs. */
final readonly class AuthorizationGrant
{
    public function __construct(
        public string $id,
        public string $scope,
        public string $description,
        public DateTimeImmutable $startDate,
        public PermissionIdentifier $authorizing,
        public PermissionIdentifier $authorized,
        public ?PermissionIdentifier $author,
    ) {}

    /** @internal parses a KSeF response */
    public static function fromPayload(Payload $data): self
    {
        return new self(
            $data->string('id'),
            $data->string('authorizationScope'),
            $data->string('description'),
            $data->date('startDate'),
            PermissionIdentifier::fromPayload($data->object('authorizingEntityIdentifier')),
            PermissionIdentifier::fromPayload($data->object('authorizedEntityIdentifier')),
            PermissionIdentifier::optional($data, 'authorIdentifier'),
        );
    }
}
