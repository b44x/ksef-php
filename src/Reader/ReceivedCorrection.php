<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Invoice\CorrectionType;
use B4x\Ksef\Support\Decimal;

/** The correction data of a `KOR`, `KOR_ZAL` or `KOR_ROZ` invoice. */
final readonly class ReceivedCorrection
{
    /**
     * @param list<ReceivedCorrectedInvoice> $correctedInvoices
     * @param string|null $period the period a collective correction refers to (`OkresFaKorygowanej`)
     * @param Decimal|null $totalBefore the amount shown before the correction (`P_15ZK`)
     */
    public function __construct(
        public array $correctedInvoices = [],
        public ?string $reason = null,
        public ?int $typeCode = null,
        public ?string $period = null,
        public ?Decimal $totalBefore = null,
    ) {}

    public function type(): ?CorrectionType
    {
        return $this->typeCode === null ? null : CorrectionType::tryFrom($this->typeCode);
    }
}
