<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

use B4x\Ksef\Http\ApiError;

/** HTTP 429: a KSeF rate limit was exceeded and the automatic retry budget is exhausted. */
final class RateLimitException extends ApiException
{
    /**
     * @param list<ApiError> $errors
     */
    public function __construct(
        string $message,
        array $errors = [],
        ?string $traceId = null,
        /** Seconds suggested by the Retry-After header. */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 429, $errors, $traceId);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
