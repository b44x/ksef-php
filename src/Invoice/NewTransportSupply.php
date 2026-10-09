<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The intra-Community supply of new means of transport on the invoice (`NoweSrodkiTransportu`). */
final readonly class NewTransportSupply
{
    /**
     * @param non-empty-list<NewMeansOfTransport> $vehicles
     * @param bool $obligationUnderArt42 the obligation of art. 42(5) of the VAT Act applies (`P_42_5`)
     */
    public function __construct(
        public array $vehicles,
        public bool $obligationUnderArt42 = false,
    ) {}
}
