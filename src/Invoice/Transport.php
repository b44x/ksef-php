<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/** One transport of the goods (`Transport` in the transaction terms). */
final readonly class Transport
{
    /**
     * @param TransportType|null $type the kind of transport; null when {@see $otherType} describes it
     * @param string|null $otherType description of another transport kind (up to 50 characters)
     * @param CargoType|null $cargo the packaging of the goods; null when {@see $otherCargo} describes it
     * @param string|null $otherCargo description of another cargo, including mixed cargo (up to 50 characters)
     * @param list<Address> $via intermediate addresses of the route, up to 20
     */
    public function __construct(
        public ?TransportType $type = null,
        public ?string $otherType = null,
        public ?CargoType $cargo = null,
        public ?string $otherCargo = null,
        public ?Carrier $carrier = null,
        public ?string $orderNumber = null,
        public ?string $packagingUnit = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?Address $from = null,
        public array $via = [],
        public ?Address $to = null,
    ) {}
}
