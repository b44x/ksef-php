<?php

declare(strict_types=1);

namespace Ksef\Status;

/** One page of invoices in a session; pass {@see self::$continuationToken} to fetch the next page. */
final readonly class SessionInvoicesPage
{
    /**
     * @param list<SessionInvoice> $invoices
     */
    public function __construct(
        public array $invoices,
        public ?string $continuationToken,
    ) {}
}
