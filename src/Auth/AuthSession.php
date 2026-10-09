<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Http\Payload;
use DateTimeImmutable;

/** An active authentication session (one login, owning one refresh token). */
final readonly class AuthSession
{
    public function __construct(
        public string $referenceNumber,
        public DateTimeImmutable $startedAt,
        public string $method,
        public int $statusCode,
        public string $statusDescription,
        public bool $isCurrent,
        public ?DateTimeImmutable $refreshTokenValidUntil,
    ) {}

    public static function fromPayload(Payload $data): self
    {
        $status = $data->object('status');

        return new self(
            $data->string('referenceNumber'),
            $data->date('startDate'),
            $data->string('authenticationMethod'),
            $status->int('code'),
            $status->string('description'),
            $data->optionalBool('isCurrent') ?? false,
            $data->optionalDate('refreshTokenValidUntil'),
        );
    }
}
