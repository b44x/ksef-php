<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Support;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Permissions\PersonSubject;
use B4x\Ksef\Support\Constraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
    #[DataProvider('pageSizes')]
    public function testPageSizesOutsideKsefsRangeAreRefused(int $size, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(ValidationException::class);
        }
        Constraint::pageSize($size, 10, 100);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function pageSizes(): iterable
    {
        yield 'below the minimum KSeF enforces' => [9, false];
        yield 'minimum' => [10, true];
        yield 'maximum' => [100, true];
        yield 'above' => [101, false];
    }

    public function testNamesOfPeopleAreCheckedLikeKsefDoes(): void
    {
        PersonSubject::byPesel('90010112345', 'Al', 'Ng');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('first name');
        PersonSubject::byPesel('90010112345', 'A', 'Nowak');
    }

    public function testPatternsAndLengthsReportTheOffendingValue(): void
    {
        try {
            Constraint::pattern('serial', 'xyz', '/^[0-9A-F]{16}$/');
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('"xyz"', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        Constraint::length('thing', 'abcd', 5, 10);
    }
}
