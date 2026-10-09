<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** One block of an attachment (`BlokDanych`): a header, descriptive data, text paragraphs and tables. */
final readonly class AttachmentBlock
{
    /**
     * @param non-empty-array<string, string> $metadata key/value descriptions of the block (at least one entry is required by the schema)
     * @param list<string> $paragraphs up to 10 paragraphs
     * @param list<AttachmentTable> $tables
     */
    public function __construct(
        public array $metadata,
        public ?string $header = null,
        public array $paragraphs = [],
        public array $tables = [],
    ) {}
}
