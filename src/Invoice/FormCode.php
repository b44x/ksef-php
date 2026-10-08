<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/** Identifies the invoice schema; sent to KSeF when a session is opened (`formCode`). */
final readonly class FormCode
{
    public const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    public function __construct(
        public string $systemCode,
        public string $schemaVersion,
        public string $value,
    ) {}

    /** FA(3), schema version 1-0E. */
    public static function fa3(): self
    {
        return new self('FA (3)', '1-0E', 'FA');
    }

    /**
     * @return array{systemCode: string, schemaVersion: string, value: string}
     */
    public function toArray(): array
    {
        return ['systemCode' => $this->systemCode, 'schemaVersion' => $this->schemaVersion, 'value' => $this->value];
    }

    public function equals(self $other): bool
    {
        return $this->systemCode === $other->systemCode && $this->schemaVersion === $other->schemaVersion && $this->value === $other->value;
    }
}
