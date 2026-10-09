<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

final readonly class AttachmentColumn
{
    public function __construct(public string $name, public ColumnType $type = ColumnType::Text) {}
}
