<?php

declare(strict_types=1);

namespace Ksef\Exception;

use Ksef\Http\ApiError;

/** HTTP 403: the caller is authenticated but lacks permissions or comes from a disallowed IP address. */
final class AuthorizationException extends ApiException
{
    /**
     * @param list<ApiError> $errors
     */
    public function __construct(
        string $message,
        int $httpStatus,
        array $errors = [],
        ?string $traceId = null,
        /** Machine readable reason such as "missing-permissions" or "ip-not-allowed". */
        public readonly ?string $reasonCode = null,
    ) {
        parent::__construct($message, $httpStatus, $errors, $traceId);
    }
}
