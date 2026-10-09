<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use DateTimeImmutable;

/**
 * A new means of transport delivered within the EU (`NowySrodekTransportu`): a land vehicle, a vessel or an aircraft
 * in the sense of art. 2(10) of the VAT Act. Build one with {@see self::landVehicle()}, {@see self::vessel()} or
 * {@see self::aircraft()}.
 */
final readonly class NewMeansOfTransport
{
    /**
     * @param array<string, string> $specific the kind-specific schema fields (`P_22B`, `P_22C`, ...) in schema order
     */
    private function __construct(
        public DateTimeImmutable $admittedOn,
        public int $lineNumber,
        public array $specific,
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $color = null,
        public ?string $registrationNumber = null,
        public ?string $productionYear = null,
    ) {}

    /**
     * @param string $mileage distance travelled, for example "1200 km"
     * @param string|null $vin at most one of $vin, $bodyNumber, $chassisNumber and $frameNumber may be given
     */
    public static function landVehicle(
        DateTimeImmutable $admittedOn,
        int $lineNumber,
        string $mileage,
        ?string $vin = null,
        ?string $bodyNumber = null,
        ?string $chassisNumber = null,
        ?string $frameNumber = null,
        ?string $vehicleType = null,
        ?string $brand = null,
        ?string $model = null,
        ?string $color = null,
        ?string $registrationNumber = null,
        ?string $productionYear = null,
    ): self {
        $specific = ['P_22B' => $mileage];
        foreach (['P_22B1' => $vin, 'P_22B2' => $bodyNumber, 'P_22B3' => $chassisNumber, 'P_22B4' => $frameNumber, 'P_22BT' => $vehicleType] as $field => $value) {
            if ($value !== null) {
                $specific[$field] = $value;
            }
        }

        return new self($admittedOn, $lineNumber, $specific, $brand, $model, $color, $registrationNumber, $productionYear);
    }

    /**
     * @param string $hoursOfUse hours of operation, for example "35 h"
     */
    public static function vessel(DateTimeImmutable $admittedOn, int $lineNumber, string $hoursOfUse, ?string $hullNumber = null, ?string $brand = null, ?string $model = null, ?string $color = null, ?string $registrationNumber = null, ?string $productionYear = null): self
    {
        return new self($admittedOn, $lineNumber, ['P_22C' => $hoursOfUse] + ($hullNumber === null ? [] : ['P_22C1' => $hullNumber]), $brand, $model, $color, $registrationNumber, $productionYear);
    }

    /**
     * @param string $hoursFlown hours flown, for example "12 h"
     */
    public static function aircraft(DateTimeImmutable $admittedOn, int $lineNumber, string $hoursFlown, ?string $factoryNumber = null, ?string $brand = null, ?string $model = null, ?string $color = null, ?string $registrationNumber = null, ?string $productionYear = null): self
    {
        return new self($admittedOn, $lineNumber, ['P_22D' => $hoursFlown] + ($factoryNumber === null ? [] : ['P_22D1' => $factoryNumber]), $brand, $model, $color, $registrationNumber, $productionYear);
    }
}
