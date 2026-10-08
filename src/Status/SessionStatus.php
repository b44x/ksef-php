<?php

declare(strict_types=1);

namespace Ksef\Status;

use DateTimeImmutable;
use Ksef\Http\Payload;

/**
 * State of an online session.
 *
 * Codes: 100 open, 170 closed (aggregate UPO is being generated), 200 processed successfully,
 * 415 key decryption error, 440 cancelled (no invoices sent), 445 verification error (no valid invoices).
 */
final readonly class SessionStatus
{
    /**
     * @param list<string> $details
     * @param list<UpoPage> $upoPages populated once the session is closed and the UPO is ready
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $validUntil,
        public ?int $invoiceCount,
        public ?int $successfulInvoiceCount,
        public ?int $failedInvoiceCount,
        public array $upoPages = [],
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $status = $data->object('status');
        $pages = [];
        $upo = $data->optionalObject('upo');
        if ($upo !== null) {
            foreach ($upo->objects('pages') as $page) {
                $pages[] = new UpoPage($page->string('referenceNumber'), $page->string('downloadUrl'), $page->date('downloadUrlExpirationDate'));
            }
        }

        return new self(
            $status->int('code'),
            $status->string('description'),
            $status->strings('details'),
            $data->date('dateCreated'),
            $data->date('dateUpdated'),
            $data->optionalDate('validUntil'),
            $data->optionalInt('invoiceCount'),
            $data->optionalInt('successfulInvoiceCount'),
            $data->optionalInt('failedInvoiceCount'),
            $pages,
        );
    }

    public function isOpen(): bool
    {
        return $this->code === 100;
    }

    /** Closed, but the aggregate UPO may still be generated. */
    public function isClosed(): bool
    {
        return $this->code >= 170;
    }

    public function isSuccessful(): bool
    {
        return $this->code === 200;
    }

    public function isFailed(): bool
    {
        return $this->code >= 400;
    }

    /** No further change is expected (success or failure). */
    public function isFinished(): bool
    {
        return $this->isSuccessful() || $this->isFailed();
    }
}
