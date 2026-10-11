<?php

declare(strict_types=1);

namespace B4x\Ksef\Latarnia;

use DateTimeImmutable;

/** An announcement about a failure or planned maintenance. */
final readonly class AvailabilityMessage
{
    /**
     * @param int $eventId the failure or maintenance this message belongs to (a start and an end message share it)
     * @param DateTimeImmutable|null $end null while the end of a failure is not known
     */
    public function __construct(
        public string $id,
        public int $eventId,
        public MessageCategory $category,
        public MessageType $type,
        public string $title,
        public string $text,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public int $version,
        public DateTimeImmutable $published,
    ) {}
}
