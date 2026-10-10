<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Pagination;

use B4x\Ksef\Pagination\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testAPageIsIterableAndCountable(): void
    {
        $page = new Page(['a', 'b'], true, 'next');

        self::assertCount(2, $page);
        self::assertSame(['a', 'b'], iterator_to_array($page));
        self::assertTrue($page->hasMore);
        self::assertSame('next', $page->continuationToken);
        self::assertFalse((new Page([]))->hasMore);
    }
}
