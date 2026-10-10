<?php

declare(strict_types=1);

namespace B4x\Ksef\Pagination;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of a listing. Iterate it, or read {@see self::$items}; ask for the next page while {@see self::$hasMore}
 * is true (offset paging: `pageOffset + 1`) or while {@see self::$continuationToken} is set (token paging).
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page implements Countable, IteratorAggregate
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore = false,
        public ?string $continuationToken = null,
    ) {}

    public function count(): int
    {
        return \count($this->items);
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
