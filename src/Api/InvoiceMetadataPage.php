<?php

declare(strict_types=1);

namespace B4x\Ksef\Api;

/**
 * One page of search results.
 *
 * Paging protocol (from the KSeF documentation): when {@see self::$hasMore} is true and
 * {@see self::$isTruncated} is false, request the next `pageOffset`; when both are true, the
 * 10,000 record cap was hit and the date range must be narrowed starting from the last
 * returned record's date, with the offset reset to 0.
 */
final readonly class InvoiceMetadataPage
{
    /**
     * @param list<InvoiceMetadata> $invoices
     */
    public function __construct(
        public array $invoices,
        public bool $hasMore,
        public bool $isTruncated,
    ) {}
}
