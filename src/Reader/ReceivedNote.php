<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

/** A free-form remark of the issuer (`DodatkowyOpis`): a key and a value, optionally tied to a line. */
final readonly class ReceivedNote
{
    public function __construct(
        public string $key,
        public string $value,
        public ?int $lineNumber = null,
    ) {}
}
