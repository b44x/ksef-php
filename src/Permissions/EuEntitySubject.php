<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Exception\ValidationException;

/**
 * Who receives EU-entity permissions. EU representatives sign in with a certificate and are identified by its
 * SHA-256 fingerprint: a person (with a national identifier) or an entity.
 */
final readonly class EuEntitySubject
{
    /**
     * @param array{type: string, value: string} $identifier
     * @param array<string, mixed> $details
     */
    private function __construct(private array $identifier, private array $details) {}

    /** A person who signs in with a certificate; build it with {@see PersonSubject::byFingerprint()}. */
    public static function person(PersonSubject $person): self
    {
        $data = $person->toArray();
        if ($data['subjectIdentifier']['type'] !== 'Fingerprint') {
            throw new ValidationException('EU entity permissions go to certificate fingerprints: build the person with PersonSubject::byFingerprint().');
        }

        return new self($data['subjectIdentifier'], $data['subjectDetails']);
    }

    /** An entity that signs in with a seal certificate. */
    public static function entity(string $sha256Fingerprint, string $fullName, string $address): self
    {
        if (preg_match('/^[0-9A-Fa-f]{64}$/', $sha256Fingerprint) !== 1) {
            throw new ValidationException('A certificate fingerprint is 64 hexadecimal characters (SHA-256).');
        }

        return new self(
            ['type' => 'Fingerprint', 'value' => strtoupper($sha256Fingerprint)],
            ['subjectDetailsType' => 'EntityByFingerprint', 'entityByFp' => ['fullName' => $fullName, 'address' => $address]],
        );
    }

    /**
     * @return array{subjectIdentifier: array{type: string, value: string}, subjectDetails: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['subjectIdentifier' => $this->identifier, 'subjectDetails' => $this->details];
    }
}
