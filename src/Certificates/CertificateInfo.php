<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** Metadata of a certificate in KSeF's register. */
final readonly class CertificateInfo
{
    public function __construct(
        public string $serialNumber,
        public string $name,
        public CertificateType $type,
        public string $commonName,
        public string $status,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validTo,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $type = CertificateType::tryFrom($data->string('type'));

        return new self(
            $data->string('certificateSerialNumber'),
            $data->string('name'),
            $type ?? throw new \B4x\Ksef\Exception\MalformedResponseException('KSeF returned an unknown certificate type.'),
            $data->string('commonName'),
            $data->string('status'),
            $data->date('validFrom'),
            $data->date('validTo'),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }
}
