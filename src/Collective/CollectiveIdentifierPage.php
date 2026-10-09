<?php

declare(strict_types=1);

namespace B4x\Ksef\Collective;

/**
 * One page of results; pass {@see self::$continuationToken} to the same call to get the next page.
 *
 * @template T
 */
final readonly class CollectiveIdentifierPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(public array $items, public ?string $continuationToken) {}

    public function hasMore(): bool
    {
        return $this->continuationToken !== null;
    }
}
