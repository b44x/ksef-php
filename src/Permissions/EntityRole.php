<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/**
 * A role of an entity (court bailiff, local government unit, VAT group unit, ...) or, when
 * {@see self::$entity} is set, of a subordinate entity towards the current context.
 */
final readonly class EntityRole
{
    public function __construct(
        public string $role,
        public string $description,
        public DateTimeImmutable $startDate,
        public ?PermissionIdentifier $entity,
    ) {}

    /** A role of the current context; {@see self::$entity} is its parent entity, if any. */
    /** @internal parses a KSeF response */
    public static function ofContext(Payload $data): self
    {
        return new self($data->string('role'), $data->string('description'), $data->date('startDate'), PermissionIdentifier::optional($data, 'parentEntityIdentifier'));
    }

    /** A subordinate entity of the current context. */
    /** @internal parses a KSeF response */
    public static function ofSubordinate(Payload $data): self
    {
        return new self($data->string('role'), $data->string('description'), $data->date('startDate'), PermissionIdentifier::optional($data, 'subordinateEntityIdentifier'));
    }
}
