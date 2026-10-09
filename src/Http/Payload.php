<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Exception\MalformedResponseException;
use DateTimeImmutable;
use Exception;

/**
 * Typed accessor over a decoded JSON object. Every getter fails with a
 * {@see MalformedResponseException} that names the offending field, so contract drift on the
 * KSeF side is reported precisely instead of surfacing as a TypeError deep inside the SDK.
 *
 * @internal
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private array $data, private string $context = 'response') {}

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : throw $this->invalid($key, 'a boolean');
    }

    /** A JSON number or numeric string as an exact decimal; JSON floats are rounded to two places (monetary values). */
    public function decimal(string $key): \B4x\Ksef\Support\Decimal
    {
        $value = $this->data[$key] ?? null;
        if (\is_int($value) || \is_string($value)) {
            try {
                return \B4x\Ksef\Support\Decimal::of($value);
            } catch (\B4x\Ksef\Exception\ValidationException $e) {
                throw new MalformedResponseException(\sprintf('Field "%s.%s" is not a valid number.', $this->context, $key), 0, $e);
            }
        }
        if (\is_float($value)) {
            return \B4x\Ksef\Support\Decimal::of(number_format($value, 2, '.', ''));
        }

        throw $this->invalid($key, 'a number');
    }

    public function date(string $key): DateTimeImmutable
    {
        return $this->parseDate($key, $this->string($key));
    }

    public function optionalDate(string $key): ?DateTimeImmutable
    {
        $value = $this->optionalString($key);

        return $value === null ? null : $this->parseDate($key, $value);
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;

        return \is_array($value) ? new self($value, $this->context . '.' . $key) : throw $this->invalid($key, 'an object');
    }

    public function optionalObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_array($value) ? new self($value, $this->context . '.' . $key) : throw $this->invalid($key, 'an object');
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw $this->invalid($key, 'an array');
        }

        $objects = [];
        foreach (array_values($value) as $index => $item) {
            if (!\is_array($item)) {
                throw $this->invalid($key . '[' . $index . ']', 'an object');
            }
            $objects[] = new self($item, $this->context . '.' . $key . '[' . $index . ']');
        }

        return $objects;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw $this->invalid($key, 'an array of strings');
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw $this->invalid($key, 'an object');
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    public function has(string $key): bool
    {
        return ($this->data[$key] ?? null) !== null;
    }

    private function parseDate(string $key, string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new MalformedResponseException(\sprintf('Field "%s.%s" is not a valid date.', $this->context, $key), 0, $e);
        }
    }

    private function invalid(string $key, string $expected): MalformedResponseException
    {
        return new MalformedResponseException(\sprintf('Field "%s.%s" is missing or not %s.', $this->context, $key, $expected));
    }
}
