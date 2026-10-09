<?php

declare(strict_types=1);

namespace B4x\Ksef\Exception;

use Throwable;

/** A domain object violates an invariant. Thrown before anything is sent to KSeF. */
class ValidationException extends KsefException
{
    /**
     * @param list<string> $violations human-readable descriptions of every violated rule
     */
    public function __construct(string $message, public readonly array $violations = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<string> $violations
     */
    public static function fromViolations(array $violations): self
    {
        return new self('Validation failed: ' . implode('; ', $violations), $violations);
    }
}
