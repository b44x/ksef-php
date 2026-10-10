<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use B4x\Ksef\Http\Payload;

/**
 * The distinguished name KSeF dictates for a certificate request. It is derived from the
 * certificate used to authenticate; changing any attribute makes KSeF reject the request.
 */
final readonly class EnrollmentData
{
    /**
     * @param list<string> $givenNames
     */
    public function __construct(
        public string $commonName,
        public string $countryName,
        public array $givenNames = [],
        public ?string $surname = null,
        public ?string $serialNumber = null,
        public ?string $uniqueIdentifier = null,
        public ?string $organizationName = null,
        public ?string $organizationIdentifier = null,
    ) {}

    /** @internal parses a KSeF response */
    public static function fromPayload(Payload $data): self
    {
        $given = $data->optionalString('givenName');

        return new self(
            $data->string('commonName'),
            $data->string('countryName'),
            $given === null ? [] : [$given],
            $data->optionalString('surname'),
            $data->optionalString('serialNumber'),
            $data->optionalString('uniqueIdentifier'),
            $data->optionalString('organizationName'),
            $data->optionalString('organizationIdentifier'),
        );
    }

    /**
     * Subject attributes as `[OID, value, ASN.1 string type]` in the order of the KSeF documentation.
     * A person with several given names yields one `givenName` attribute per name, as KSeF requires.
     *
     * @return list<array{string, string, string}>
     */
    public function toAttributes(): array
    {
        $attributes = [['2.5.4.3', $this->commonName, 'utf8String']];
        if ($this->surname !== null) {
            $attributes[] = ['2.5.4.4', $this->surname, 'utf8String'];
        }
        if ($this->serialNumber !== null) {
            $attributes[] = ['2.5.4.5', $this->serialNumber, 'printableString'];
        }
        $attributes[] = ['2.5.4.6', $this->countryName, 'printableString'];
        if ($this->organizationName !== null) {
            $attributes[] = ['2.5.4.10', $this->organizationName, 'utf8String'];
        }
        foreach ($this->givenNames as $given) {
            $attributes[] = ['2.5.4.42', $given, 'utf8String'];
        }
        if ($this->uniqueIdentifier !== null) {
            $attributes[] = ['2.5.4.45', $this->uniqueIdentifier, 'utf8String'];
        }
        if ($this->organizationIdentifier !== null) {
            $attributes[] = ['2.5.4.97', $this->organizationIdentifier, 'utf8String'];
        }

        return $attributes;
    }
}
