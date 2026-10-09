<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Nip;

/** A subordinate unit or entity (local government unit, VAT group member) identified by NIP or internal identifier. */
final readonly class SubunitContext
{
    private function __construct(private string $type, private string $value) {}

    public static function nip(Nip $nip): self
    {
        return new self('Nip', $nip->value);
    }

    /** The NIP of the parent, a dash and five digits (for example "1234563218-12345"). */
    public static function internalId(string $id): self
    {
        if (preg_match('/^\d{10}-\d{5}$/', $id) !== 1) {
            throw new ValidationException('An internal identifier looks like "1234563218-12345" (NIP, dash, five digits).');
        }

        return new self('InternalId', $id);
    }

    /**
     * @return array{type: string, value: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
