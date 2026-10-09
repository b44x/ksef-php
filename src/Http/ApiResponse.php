<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Exception\MalformedResponseException;
use JsonException;

/** A successful (2xx) answer from the KSeF API. */
final readonly class ApiResponse
{
    /**
     * @param array<string, string> $headers header values keyed by lower-case name
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decodes the body as a JSON object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedResponseException('KSeF returned a body that is not valid JSON.', 0, $e);
        }

        if (!\is_array($data)) {
            throw new MalformedResponseException('KSeF returned a JSON body that is not an object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
