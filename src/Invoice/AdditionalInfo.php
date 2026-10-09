<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** A free key/value remark on the invoice (`DodatkowyOpis`), optionally tied to one line (1-based). */
final readonly class AdditionalInfo
{
    public function __construct(
        public string $key,
        public string $value,
        public ?int $lineNumber = null,
    ) {}
}
