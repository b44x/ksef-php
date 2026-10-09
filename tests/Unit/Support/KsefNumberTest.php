<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Support;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\KsefNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KsefNumberTest extends TestCase
{
    public function testAcceptsTheOfficialExampleNumber(): void
    {
        // Example from the KSeF documentation ("Numer KSeF – struktura i walidacja").
        self::assertSame('5265877635-20250826-0100001AF629-AF', KsefNumber::of('5265877635-20250826-0100001AF629-AF')->value);
        self::assertSame('AF', KsefNumber::crc8('5265877635-20250826-0100001AF629'));
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumbers(string $number, string $reason): void
    {
        self::assertStringContainsString($reason, (string) KsefNumber::validate($number));
        $this->expectException(ValidationException::class);
        KsefNumber::of($number);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'wrong checksum' => ['5265877635-20250826-0100001AF629-00', 'checksum'];
        yield 'too short' => ['5265877635-20250826-0100001AF629', '35 characters'];
        yield 'lower case' => ['5265877635-20250826-0100001af629-AF', 'structure'];
        yield 'bad month' => ['5265877635-20251326-0100001AF629-AF', 'structure'];
    }
}
