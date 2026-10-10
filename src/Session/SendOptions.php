<?php

declare(strict_types=1);

namespace B4x\Ksef\Session;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Offline\OfflineInvoice;

/**
 * Per-submission flags of `SendInvoiceRequest`.
 *
 * - {@see self::offline()}: the invoice was issued in an offline mode and is delivered late (`offlineMode`).
 * - {@see self::technicalCorrection()}: re-sends an offline invoice that KSeF rejected for technical reasons,
 *   linking it to the rejected one (`hashOfCorrectedInvoice`). Interactive sessions only; the content must not change.
 */
final readonly class SendOptions
{
    private function __construct(
        public bool $offline = false,
        public ?string $correctedInvoiceHash = null,
    ) {}

    /** Declares the offline mode (`offlineMode: true`) for the invoice. */
    public static function offline(): self
    {
        return new self(true);
    }

    /**
     * @param string|InvoiceDocument|OfflineInvoice $rejected the rejected offline invoice, or its Base64 SHA-256 hash
     *
     * @throws ValidationException when a hash is given that is not a Base64 SHA-256 value
     */
    public static function technicalCorrection(string|InvoiceDocument|OfflineInvoice $rejected): self
    {
        $hash = match (true) {
            $rejected instanceof OfflineInvoice => $rejected->document->hash(),
            $rejected instanceof InvoiceDocument => $rejected->hash(),
            default => $rejected,
        };
        $raw = base64_decode($hash, true);
        if ($raw === false || \strlen($raw) !== 32) {
            throw new ValidationException('The hash of the corrected invoice must be a Base64 encoded SHA-256 value.');
        }

        return new self(true, $hash);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [];
        if ($this->offline) {
            $payload['offlineMode'] = true;
        }
        if ($this->correctedInvoiceHash !== null) {
            $payload['hashOfCorrectedInvoice'] = $this->correctedInvoiceHash;
        }

        return $payload;
    }
}
