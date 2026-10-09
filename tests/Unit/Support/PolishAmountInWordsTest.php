<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Support;

use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\PolishAmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolishAmountInWordsTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testAmountsAreWrittenWithCorrectDeclension(string $amount, string $expected): void
    {
        self::assertSame($expected, PolishAmountInWords::pln(Decimal::of($amount)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => ['0.00', 'zero złotych zero groszy'];
        yield 'one' => ['1.01', 'jeden złoty jeden grosz'];
        yield 'paucal' => ['2.03', 'dwa złote trzy grosze'];
        yield 'teens are plural' => ['12.14', 'dwanaście złotych czternaście groszy'];
        yield 'twenty-two' => ['22.22', 'dwadzieścia dwa złote dwadzieścia dwa grosze'];
        yield 'hundreds' => ['123.45', 'sto dwadzieścia trzy złote czterdzieści pięć groszy'];
        yield 'thousand' => ['1000.00', 'tysiąc złotych zero groszy'];
        yield 'two thousand' => ['2000.50', 'dwa tysiące złotych pięćdziesiąt groszy'];
        yield 'twelve thousand' => ['12000.00', 'dwanaście tysięcy złotych zero groszy'];
        yield 'mixed' => ['1234567.89', 'milion dwieście trzydzieści cztery tysiące pięćset sześćdziesiąt siedem złotych osiemdziesiąt dziewięć groszy'];
        yield 'billion' => ['3000000000.00', 'trzy miliardy złotych zero groszy'];
        yield 'rounded' => ['9.999', 'dziesięć złotych zero groszy'];
        yield 'negative' => ['-5.50', 'minus pięć złotych pięćdziesiąt groszy'];
    }
}
