<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\ValidationException;

/**
 * The taxpayer (or other entity) on whose behalf the session works, for example a seller NIP.
 *
 * The formats are those enforced by KSeF's own authentication schema.
 */
final readonly class ContextIdentifier
{
    private const NIP = '/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/';
    private const INTERNAL_ID = '/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-\d{5}$/';
    private const PEPPOL_ID = '/^P[A-Z]{2}[0-9]{6}$/';
    private const NIP_VAT_UE = '/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-[A-Z]{2}[A-Z0-9+*]{2,12}$/';

    private function __construct(
        public ContextIdentifierType $type,
        public string $value,
    ) {}

    public static function nip(string $nip): self
    {
        return self::create(ContextIdentifierType::Nip, $nip, self::NIP);
    }

    /** Internal identifier: NIP, a dash and five digits. */
    public static function internalId(string $id): self
    {
        return self::create(ContextIdentifierType::InternalId, $id, self::INTERNAL_ID);
    }

    /** Composite context: NIP, a dash and the EU VAT number including the country prefix. */
    public static function nipVatUe(string $value): self
    {
        return self::create(ContextIdentifierType::NipVatUe, $value, self::NIP_VAT_UE);
    }

    public static function peppolId(string $id): self
    {
        return self::create(ContextIdentifierType::PeppolId, $id, self::PEPPOL_ID);
    }

    /**
     * @return array{type: string, value: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }

    private static function create(ContextIdentifierType $type, string $value, string $pattern): self
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new ValidationException(\sprintf('"%s" is not a valid %s context identifier.', $value, $type->value), [\sprintf('Invalid %s: %s', $type->value, $value)]);
        }

        return new self($type, $value);
    }
}
