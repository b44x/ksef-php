<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;

/** Terms of the transaction the invoice settles (`WarunkiTransakcji`): the contracts and orders, batch numbers and delivery terms. */
final readonly class TransactionTerms
{
    /**
     * @param list<DocumentReference> $contracts up to 100
     * @param list<DocumentReference> $orders up to 100
     * @param list<string> $batchNumbers numbers of the goods batches, up to 1000
     * @param string|null $deliveryTerms for example an Incoterms clause
     * @param Decimal|null $contractualRate the agreed exchange rate of the contractual currency (`KursUmowny`); needs {@see $contractualCurrency}
     * @param string|null $contractualCurrency the currency agreed in the contract (`WalutaUmowna`)
     * @param list<Transport> $transports up to 20
     * @param bool $intermediary the delivery is made by an intermediary under art. 22(2e) of the VAT Act (`PodmiotPosredniczacy`)
     */
    public function __construct(
        public array $contracts = [],
        public array $orders = [],
        public array $batchNumbers = [],
        public ?string $deliveryTerms = null,
        public ?Decimal $contractualRate = null,
        public ?string $contractualCurrency = null,
        public array $transports = [],
        public bool $intermediary = false,
    ) {}
}
