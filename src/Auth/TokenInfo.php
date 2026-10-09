<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\MalformedResponseException;
use DateTimeImmutable;
use Exception;
use SensitiveParameter;

/** A bearer token with its expiry. The token string is a secret and is hidden from dumps. */
final readonly class TokenInfo
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public DateTimeImmutable $validUntil,
    ) {}

    /**
     * @param mixed $data decoded `TokenInfo` JSON object
     */
    public static function fromApi(mixed $data): self
    {
        if (!\is_array($data) || !\is_string($data['token'] ?? null) || !\is_string($data['validUntil'] ?? null)) {
            throw new MalformedResponseException('KSeF returned a token object with an unexpected structure.');
        }

        try {
            return new self($data['token'], new DateTimeImmutable($data['validUntil']));
        } catch (Exception $e) {
            throw new MalformedResponseException('KSeF returned a token with an invalid expiry date.', 0, $e);
        }
    }

    /** True when the token is expired or will expire within the given safety margin. */
    public function expiresWithin(DateTimeImmutable $now, int $marginSeconds = 0): bool
    {
        return $this->validUntil->getTimestamp() - $marginSeconds <= $now->getTimestamp();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '***', 'validUntil' => $this->validUntil->format(\DATE_ATOM)];
    }
}
