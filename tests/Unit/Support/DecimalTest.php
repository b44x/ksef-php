<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Support;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function testArithmeticIsExactWhereFloatsAreNot(): void
    {
        self::assertSame('0.30', Decimal::of('0.10')->add(Decimal::of('0.20'))->toString());
        self::assertSame('0.3', Decimal::of('0.1')->add(Decimal::of('0.2'))->toString());
        self::assertSame('-1.50', Decimal::of('1.00')->subtract(Decimal::of('2.50'))->toString());
        self::assertSame('7.5000', Decimal::of('2.50')->multiply(Decimal::of('3.00'))->toString());
    }

    #[DataProvider('roundingCases')]
    public function testRoundsHalfAwayFromZero(string $input, int $places, string $expected): void
    {
        self::assertSame($expected, Decimal::of($input)->toString($places));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function roundingCases(): iterable
    {
        yield 'half up' => ['2.345', 2, '2.35'];
        yield 'below half' => ['2.344', 2, '2.34'];
        yield 'negative half' => ['-2.345', 2, '-2.35'];
        yield 'pad' => ['2.5', 2, '2.50'];
        yield 'to integer' => ['0.5', 0, '1'];
        yield 'small negative to zero' => ['-0.004', 2, '0.00'];
    }

    public function testPercentOfAnAmount(): void
    {
        self::assertSame('46.00', Decimal::of('200.00')->percent(Decimal::of('23'))->toString(2));
        self::assertSame('0.46', Decimal::of('2.00')->percent(Decimal::of('23'))->toString(2));
        self::assertSame('2.30', Decimal::of('10.00')->percent(Decimal::of('23'))->toString(2));
    }

    public function testComparisonIgnoresScale(): void
    {
        self::assertTrue(Decimal::of('1.0')->equals(Decimal::of('1.000')));
        self::assertSame(-1, Decimal::of('1.99')->compare(Decimal::of('2')));
        self::assertTrue(Decimal::of('0.00')->isZero());
        self::assertTrue(Decimal::of('-0.01')->isNegative());
        self::assertTrue(Decimal::of('0.01')->isPositive());
    }

    public function testTrimmedStringKeepsMinimumPlaces(): void
    {
        self::assertSame('1.50', Decimal::of('1.500000')->toTrimmedString(2));
        self::assertSame('1.25', Decimal::of('1.250')->toTrimmedString(2));
        self::assertSame('10', Decimal::of('10')->toTrimmedString());
        self::assertSame('0.00', Decimal::of('0.00')->toTrimmedString(2));
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(ValidationException::class);
        Decimal::of($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'exponent' => ['1e3'];
        yield 'comma' => ['1,5'];
        yield 'plus' => ['+1'];
        yield 'too long' => ['99999999999999999999'];
    }

    public function testOverflowIsDetectedInsteadOfWrappingAround(): void
    {
        $this->expectException(ValidationException::class);
        Decimal::of('9000000000000000000')->multiply(Decimal::of('10'));
    }

    public function testMultiplyingHighPrecisionOperandsReducesPrecisionInsteadOfFailing(): void
    {
        $result = Decimal::of('1.123456789012')->multiply(Decimal::of('2.123456789012'));

        self::assertSame('2.39', $result->toString(2));
    }

    public function testDivisionRoundsHalfAwayFromZero(): void
    {
        self::assertSame('1.87', Decimal::of('123.00')->multiply(Decimal::of('23'))->dividedBy(Decimal::of('123'), 2)->dividedBy(Decimal::of('12.3'), 2)->toString(2));
        self::assertSame('23.00', Decimal::of('123.00')->multiply(Decimal::of('23'))->dividedBy(Decimal::of('123'), 2)->toString(2));
        self::assertSame('0.33', Decimal::of('1')->dividedBy(Decimal::of('3'), 2)->toString(2));
        self::assertSame('0.67', Decimal::of('2')->dividedBy(Decimal::of('3'), 2)->toString(2));
        self::assertSame('-0.67', Decimal::of('-2')->dividedBy(Decimal::of('3'), 2)->toString(2));
        self::assertSame('0.50', Decimal::of('1.0')->dividedBy(Decimal::of('2.0'), 2)->toString(2));
        self::assertSame('1', Decimal::of('5')->dividedBy(Decimal::of('5'), 0)->toString());
    }

    public function testDivisionByZeroIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        Decimal::of('1')->dividedBy(Decimal::of('0'), 2);
    }
}
