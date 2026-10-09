<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

/** One error entry reported by KSeF (exception code, description and details). */
final readonly class ApiError
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public ?int $code,
        public string $description,
        public array $details = [],
    ) {}
}
