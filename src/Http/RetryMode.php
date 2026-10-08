<?php

declare(strict_types=1);

namespace Ksef\Http;

/**
 * How aggressively a request may be repeated after a failure.
 *
 * The mode is chosen per endpoint by the library; it is never configurable for mutating calls.
 */
enum RetryMode
{
    /** Read-only or naturally idempotent call: retry network errors, 429 and transient 5xx. */
    case Safe;

    /**
     * Mutating call: retry only HTTP 429. A 429 is returned before the request is processed, so a
     * repeat cannot duplicate anything. Network errors and 5xx are surfaced to the caller instead.
     */
    case RateLimitOnly;

    /** Never retry. */
    case Never;
}
