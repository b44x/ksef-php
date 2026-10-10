<?php

declare(strict_types=1);

namespace B4x\Ksef\Export;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/**
 * State of an invoice export: 100 in progress, 200 ready, 210 expired, 415 key decryption error,
 * 420 filter outside the available data range, 500 unknown error, 550 cancelled by the system.
 */
final readonly class ExportStatus
{
    /**
     * @param list<string> $details
     * @param list<ExportPart> $parts
     */
    public function __construct(
        public int $code,
        public string $description,
        public array $details,
        public array $parts,
        public ?int $invoiceCount,
        public bool $isTruncated,
        public ?DateTimeImmutable $lastPermanentStorageDate,
        public ?DateTimeImmutable $permanentStorageHwmDate,
    ) {}

    /** @internal parses a KSeF response */
    public static function fromPayload(Payload $data): self
    {
        $status = $data->object('status');
        $package = $data->optionalObject('package');

        $parts = [];
        if ($package !== null) {
            foreach ($package->objects('parts') as $part) {
                $parts[] = new ExportPart(
                    $part->int('ordinalNumber'),
                    $part->string('url'),
                    $part->int('encryptedPartSize'),
                    $part->string('encryptedPartHash'),
                    $part->int('partSize'),
                    $part->string('partHash'),
                    $part->date('expirationDate'),
                );
            }
        }

        return new self(
            $status->int('code'),
            $status->string('description'),
            $status->strings('details'),
            $parts,
            $package?->optionalInt('invoiceCount'),
            $package?->optionalBool('isTruncated') ?? false,
            $package?->optionalDate('lastPermanentStorageDate'),
            $package?->optionalDate('permanentStorageHwmDate'),
        );
    }

    public function isInProgress(): bool
    {
        return $this->code === 100;
    }

    public function isReady(): bool
    {
        return $this->code === 200;
    }
}
