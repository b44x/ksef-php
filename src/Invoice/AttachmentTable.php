<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** A table in an attachment block. Cells are given as text in the format of the column type. */
final readonly class AttachmentTable
{
    /**
     * @param non-empty-list<AttachmentColumn> $columns up to 20
     * @param list<list<string>> $rows every row has one cell per column
     * @param list<string>|null $totals an optional summary row with one cell per column
     * @param array<string, string> $metadata descriptive key/value pairs about the table
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public ?string $description = null,
        public ?array $totals = null,
        public array $metadata = [],
    ) {}
}
