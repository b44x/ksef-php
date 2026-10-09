<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Terms of the transaction the invoice settles (`WarunkiTransakcji`): the contracts and orders, batch numbers and delivery terms. */
final readonly class TransactionTerms
{
    /**
     * @param list<DocumentReference> $contracts up to 100
     * @param list<DocumentReference> $orders up to 100
     * @param list<string> $batchNumbers numbers of the goods batches, up to 1000
     * @param string|null $deliveryTerms for example an Incoterms clause
     */
    public function __construct(
        public array $contracts = [],
        public array $orders = [],
        public array $batchNumbers = [],
        public ?string $deliveryTerms = null,
    ) {}
}
