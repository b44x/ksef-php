<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Nip;

/** The natural person who receives permissions: identified by NIP, PESEL or a certificate fingerprint. */
final readonly class PersonSubject
{
    /**
     * @param array{type: string, value: string} $identifier
     * @param array<string, mixed> $details
     */
    private function __construct(
        private array $identifier,
        private array $details,
    ) {}

    public static function byNip(Nip $nip, string $firstName, string $lastName): self
    {
        return new self(['type' => 'Nip', 'value' => $nip->value], self::byIdentifier($firstName, $lastName));
    }

    public static function byPesel(string $pesel, string $firstName, string $lastName): self
    {
        if (preg_match('/^\d{11}$/', $pesel) !== 1) {
            throw new ValidationException('A PESEL has exactly 11 digits.');
        }

        return new self(['type' => 'Pesel', 'value' => $pesel], self::byIdentifier($firstName, $lastName));
    }

    /**
     * A person who authenticates with a certificate that carries no NIP/PESEL, identified by its SHA-256
     * fingerprint (lower-case hex), together with the person's own NIP or PESEL.
     */
    public static function byFingerprint(string $sha256Fingerprint, string $firstName, string $lastName, Nip|string $nipOrPesel): self
    {
        if (preg_match('/^[0-9A-Fa-f]{64}$/', $sha256Fingerprint) !== 1) {
            throw new ValidationException('A certificate fingerprint is 64 hexadecimal characters (SHA-256).');
        }
        $identifier = $nipOrPesel instanceof Nip ? ['type' => 'Nip', 'value' => $nipOrPesel->value] : ['type' => 'Pesel', 'value' => $nipOrPesel];

        return new self(
            ['type' => 'Fingerprint', 'value' => strtoupper($sha256Fingerprint)],
            ['subjectDetailsType' => 'PersonByFingerprintWithIdentifier', 'personByFpWithId' => ['firstName' => $firstName, 'lastName' => $lastName, 'identifier' => $identifier]],
        );
    }

    /**
     * @return array{subjectIdentifier: array{type: string, value: string}, subjectDetails: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['subjectIdentifier' => $this->identifier, 'subjectDetails' => $this->details];
    }

    /**
     * @return array<string, mixed>
     */
    private static function byIdentifier(string $firstName, string $lastName): array
    {
        return ['subjectDetailsType' => 'PersonByIdentifier', 'personById' => ['firstName' => $firstName, 'lastName' => $lastName]];
    }
}
