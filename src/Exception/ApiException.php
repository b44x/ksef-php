<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

use B4x\Ksef\Http\ApiError;
use Throwable;

/**
 * KSeF answered with an error status.
 *
 * Only diagnostic metadata is exposed; request bodies and credentials are never part of it.
 */
class ApiException extends KsefException
{
    /**
     * @param list<ApiError> $errors errors reported by KSeF, in the order received
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $errors = [],
        public readonly ?string $traceId = null,
        public readonly ?Throwable $cause = null,
    ) {
        parent::__construct($message, $httpStatus, $cause);
    }

    /** First KSeF error code (for example 21405), if the response carried one. */
    public function ksefCode(): ?int
    {
        return $this->errors[0]->code ?? null;
    }

    public function isRetryable(): bool
    {
        return $this->httpStatus >= 500;
    }
}
