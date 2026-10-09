<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

/** Result of opening a batch session. */
final readonly class OpenedBatch
{
    /**
     * @param list<PartUpload> $uploads
     */
    public function __construct(
        public string $referenceNumber,
        public array $uploads,
    ) {}
}
