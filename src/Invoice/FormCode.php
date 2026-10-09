<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Identifies the invoice schema; sent to KSeF when a session is opened (`formCode`). */
final readonly class FormCode
{
    public const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';
    public const RR_NAMESPACE = 'http://crd.gov.pl/wzor/2026/03/06/14189/';
    public const PEF_INVOICE_NAMESPACE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const PEF_CREDIT_NOTE_NAMESPACE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

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

    /** FA_RR (1), schema version 1-1E: flat-rate farmer purchase invoices. */
    public static function rr(): self
    {
        return new self('FA_RR (1)', '1-1E', 'FA_RR');
    }

    /** PEF (3), schema version 2-1: a Peppol (UBL) invoice. */
    public static function pef(): self
    {
        return new self('PEF (3)', '2-1', 'PEF');
    }

    /** PEF_KOR (3), schema version 2-1: a Peppol (UBL) credit note. */
    public static function pefCorrection(): self
    {
        return new self('PEF_KOR (3)', '2-1', 'PEF');
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
