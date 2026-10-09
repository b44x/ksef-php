<?php

declare(strict_types=1);

namespace B4x\Ksef\Support;

use B4x\Ksef\Exception\ValidationException;

/** Writes a PLN amount in Polish words, as the FA_RR invoice requires (`P_12_2`). */
final class PolishAmountInWords
{
    private const UNITS = ['zero', 'jeden', 'dwa', 'trzy', 'cztery', 'pięć', 'sześć', 'siedem', 'osiem', 'dziewięć', 'dziesięć', 'jedenaście', 'dwanaście', 'trzynaście', 'czternaście', 'piętnaście', 'szesnaście', 'siedemnaście', 'osiemnaście', 'dziewiętnaście'];
    private const TENS = ['', '', 'dwadzieścia', 'trzydzieści', 'czterdzieści', 'pięćdziesiąt', 'sześćdziesiąt', 'siedemdziesiąt', 'osiemdziesiąt', 'dziewięćdziesiąt'];
    private const HUNDREDS = ['', 'sto', 'dwieście', 'trzysta', 'czterysta', 'pięćset', 'sześćset', 'siedemset', 'osiemset', 'dziewięćset'];
    private const SCALES = [
        1 => ['tysiąc', 'tysiące', 'tysięcy'],
        2 => ['milion', 'miliony', 'milionów'],
        3 => ['miliard', 'miliardy', 'miliardów'],
        4 => ['bilion', 'biliony', 'bilionów'],
    ];

    /** "sto dwadzieścia trzy złote czterdzieści pięć groszy"; negative amounts start with "minus". */
    public static function pln(Decimal $amount): string
    {
        $rounded = $amount->roundTo(2);
        $text = $rounded->abs()->toString(2);
        [$whole, $fraction] = explode('.', $text);
        if (\strlen($whole) > 15) {
            throw new ValidationException('The amount is too large to be written in words.');
        }

        $zloty = (int) $whole;
        $grosze = (int) $fraction;
        $words = self::number($zloty) . ' ' . self::form($zloty, ['złoty', 'złote', 'złotych'])
            . ' ' . self::number($grosze) . ' ' . self::form($grosze, ['grosz', 'grosze', 'groszy']);

        return ($rounded->isNegative() ? 'minus ' : '') . $words;
    }

    private static function number(int $n): string
    {
        if ($n < 1000) {
            return self::below1000($n);
        }

        $parts = [];
        $scale = 0;
        while ($n > 0) {
            $group = $n % 1000;
            $n = intdiv($n, 1000);
            if ($group > 0) {
                $parts[] = $scale === 0
                    ? self::below1000($group)
                    : (($group === 1 ? '' : self::below1000($group) . ' ') . self::form($group, self::SCALES[$scale]));
            }
            ++$scale;
        }

        return implode(' ', array_reverse($parts));
    }

    private static function below1000(int $n): string
    {
        if ($n < 20) {
            return self::UNITS[$n];
        }
        $words = [];
        if ($n >= 100) {
            $words[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $words[] = self::TENS[intdiv($n, 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $words[] = self::UNITS[$n];
        }

        return implode(' ', $words);
    }

    /**
     * @param array{string, string, string} $forms singular, paucal (2-4) and plural
     */
    private static function form(int $n, array $forms): string
    {
        if ($n === 1) {
            return $forms[0];
        }
        $lastTwo = $n % 100;
        $last = $n % 10;
        if ($last >= 2 && $last <= 4 && !($lastTwo >= 12 && $lastTwo <= 14)) {
            return $forms[1];
        }

        return $forms[2];
    }
}
