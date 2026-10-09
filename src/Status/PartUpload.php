<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

/** Where and how one encrypted batch part must be uploaded (as prescribed by KSeF). */
final readonly class PartUpload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $ordinalNumber,
        public string $method,
        public string $url,
        public array $headers,
    ) {}
}
