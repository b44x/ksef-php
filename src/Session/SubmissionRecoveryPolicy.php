<?php

declare(strict_types=1);

namespace B4x\Ksef\Session;

use B4x\Ksef\Exception\ConfigurationException;

/**
 * What the SDK does on its own when a submission ends without a definitive answer
 * (network failure or 5xx while the invoice was being sent).
 *
 * Recovery is safe because KSeF detects duplicates globally (seller NIP + type + number, status 440):
 * 1. the session is searched for the document's hash; if KSeF has it, the submission is returned as recovered;
 * 2. otherwise the *identical* document is sent again, at most {@see self::$maxResends} times,
 *    with a growing delay;
 * 3. if nothing is conclusive, `SubmissionOutcomeUnknownException` is thrown.
 */
final readonly class SubmissionRecoveryPolicy
{
    public function __construct(
        public int $maxResends = 2,
        public float $baseDelaySeconds = 1.0,
        public float $maxDelaySeconds = 10.0,
    ) {
        if ($maxResends < 0 || $baseDelaySeconds < 0 || $maxDelaySeconds < $baseDelaySeconds) {
            throw new ConfigurationException('Invalid submission recovery policy.');
        }
    }

    /** Surface every ambiguous outcome to the caller immediately. */
    public static function disabled(): self
    {
        return new self(0, 0.0, 0.0);
    }

    public function isEnabled(): bool
    {
        return $this->maxResends > 0;
    }

    public function delayBeforeResend(int $resend): float
    {
        return min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** max(0, $resend - 1)));
    }
}
