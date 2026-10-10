<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\KsefNumber;
use DateTimeImmutable;

/**
 * Checks invoice invariants that do not need KSeF.
 *
 * Rules mirror the FA(3) schema restrictions (lengths, ranges, allowed characters) and the
 * semantic rules that would otherwise be rejected remotely (for example a missing exemption basis).
 * The result is a complete list of violations, never just the first one.
 *
 * @internal
 */
final class InvoiceValidator
{
    private const MAX_LINES = 10_000;
    private const FORBIDDEN_CHARACTERS = '/[\x{7F}-\x{84}\x{86}-\x{9F}\x{FDD0}-\x{FDEF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}]/u';

    /** @var list<string> */
    private array $violations = [];

    private function __construct(private readonly Invoice $invoice) {}

    /**
     * @return list<string>
     */
    public static function violations(Invoice $invoice): array
    {
        $validator = new self($invoice);
        $validator->run();

        return $validator->violations;
    }

    private function run(): void
    {
        $i = $this->invoice;

        $this->text('Invoice number', $i->number, 256);
        $this->date('Issue date', $i->issueDate);
        if ($i->saleDate !== null) {
            $this->date('Sale date', $i->saleDate);
        }
        if ($i->issuePlace !== null) {
            $this->text('Issue place', $i->issuePlace, 256);
        }
        if (preg_match('/^[A-Z]{3}$/', $i->currency) !== 1) {
            $this->add(\sprintf('Currency "%s" must be a three-letter ISO 4217 code in upper case.', $i->currency));
        }
        if ($i->footer !== null) {
            $this->text('Footer', $i->footer, 3500);
        }

        $this->party('Seller', $i->seller->name, $i->seller->address, $i->seller->email, $i->seller->phone);
        $this->party('Buyer', $i->buyer->name, $i->buyer->address, $i->buyer->email, $i->buyer->phone);
        if ($i->buyer->customerNumber !== null) {
            $this->text('Buyer customer number', $i->buyer->customerNumber, 256);
        }
        $this->buyerIdentifier($i->buyer->identifier);
        $this->partyExtras();
        $this->thirdParties();
        $this->authorizedEntity();
        $this->extras();
        $this->newTransport();

        $this->lines();
        $this->taxTreatment();
        $this->payment();
        $this->correction();
        $this->kindSpecificRules();
        $this->amounts();
    }

    private function lines(): void
    {
        $i = $this->invoice;
        $count = \count($i->lines);
        if ($i->isCollectiveCorrection()) {
            if ($count !== 0) {
                $this->add('A collective correction (art. 106j(3)) has no lines: give the differences per rate in Correction::$amounts.');
            }

            return;
        }
        if ($count < 1 || $count > self::MAX_LINES) {
            $this->add(\sprintf('An invoice needs between 1 and %d lines, %d given.', self::MAX_LINES, $count));
        }

        $before = 0;
        $reversals = 0;
        foreach ($i->lines as $index => $line) {
            $label = \sprintf('Line %d', $index + 1);
            $this->text($label . ' name', $line->name, 512);
            if ($line->unit !== null) {
                $this->text($label . ' unit', $line->unit, 256);
            }
            foreach (['PKWiU' => $line->pkwiu, 'CN' => $line->cn, 'internal code' => $line->internalCode] as $field => $value) {
                if ($value !== null) {
                    $this->text($label . ' ' . $field, $value, 50);
                }
            }
            if ($line->gtin !== null) {
                $this->text($label . ' GTIN', $line->gtin, 20);
            }
            // Corrections "by difference" or "by reversal" (handbook 2.13.4) show a negative quantity or price.
            $mayBeNegative = $line->state === LineState::Current && ($i->type === InvoiceType::Correction || $i->type === InvoiceType::SettlementCorrection);
            if (($line->quantity->isNegative() && !$mayBeNegative) || ($line->state === LineState::Current && !$i->type->isCorrection() && $line->quantity->isZero())) {
                $this->add($label . ': the quantity must be positive.');
            }
            if ($line->quantity->isNegative() && $line->unitNetPrice->isNegative()) {
                $this->add($label . ': give a negative quantity or a negative price, not both.');
            }
            if ($line->quantity->scale() > 6 && !$line->quantity->equals($line->quantity->roundTo(6))) {
                $this->add($label . ': the quantity allows at most 6 decimal places.');
            }
            if ($line->unitNetPrice->isNegative() && !$mayBeNegative) {
                $this->add($label . ': the unit price must not be negative.');
            }
            if ($line->state === LineState::Current && $line->netAmount()->isNegative()) {
                ++$reversals;
            }
            if ($line->unitNetPrice->amount->scale() > 8 && !$line->unitNetPrice->amount->equals($line->unitNetPrice->amount->roundTo(8))) {
                $this->add($label . ': the unit price allows at most 8 decimal places.');
            }
            if ($line->unitNetPrice->currency !== $i->currency) {
                $this->add(\sprintf('%s: the price currency %s differs from the invoice currency %s.', $label, $line->unitNetPrice->currency, $i->currency));
            }
            if ($line->discount !== null) {
                if ($line->discount->currency !== $i->currency) {
                    $this->add($label . ': the discount currency differs from the invoice currency.');
                }
                if ($line->discount->isNegative()) {
                    $this->add($label . ': the discount must not be negative.');
                }
                if ($line->discount->amount->scale() > 8 && !$line->discount->amount->equals($line->discount->amount->roundTo(8))) {
                    $this->add($label . ': the discount allows at most 8 decimal places.');
                }
            }
            if ($line->excise !== null && ($line->excise->isNegative() || $line->excise->currency !== $i->currency)) {
                $this->add($label . ': the excise amount must not be negative and must be in the invoice currency.');
            }
            if ($line->deliveryDate !== null) {
                $this->date($label . ' delivery date', $line->deliveryDate);
            }
            if ($line->state === LineState::Before) {
                ++$before;
            }
        }

        if (!$i->type->isCorrection() && $before > 0) {
            $this->add('"Before correction" lines are only allowed on correction invoices.');
        }
        if ($i->type->isCorrection() && $before === 0 && ($reversals === 0 || $i->type === InvoiceType::AdvanceCorrection)) {
            $this->add('A correction invoice needs at least one "before correction" line (InvoiceLine::asBefore()), or lines with negative values that reverse the original ones.');
        }
    }

    private function taxTreatment(): void
    {
        $i = $this->invoice;

        /** @var array<string, array<string, true>> $ratesPerBucket */
        $ratesPerBucket = [];
        foreach ($i->lines as $line) {
            $ratesPerBucket[$line->vatRate->bucket()][$line->vatRate->value] = true;
        }
        foreach ($i->correction->amounts ?? [] as $amount) {
            $ratesPerBucket[$amount->rate->bucket()][$amount->rate->value] = true;
        }
        foreach ($ratesPerBucket as $rates) {
            if (\count($rates) > 1) {
                $this->add(\sprintf('Rates %s cannot be mixed on one invoice (they share a totals field).', implode(' and ', array_keys($rates))));
            }
        }

        if ($i->hasLinesWith(VatRate::Exempt)) {
            $basis = $i->annotations->exemptionBasis;
            if ($basis === null || trim($basis) === '') {
                $this->add('A line with the exempt rate ("zw") requires Annotations::$exemptionBasis (the legal basis for the exemption).');
            } else {
                $this->text('Exemption basis', $basis, 256);
            }
        }

        $taxed = false;
        foreach ($i->lines as $line) {
            $taxed = $taxed || $line->vatRate->isTaxed();
        }
        foreach ($i->correction->amounts ?? [] as $amount) {
            $taxed = $taxed || $amount->rate->isTaxed();
        }
        if ($i->currency !== 'PLN' && $taxed) {
            if ($i->exchangeRate === null || !$i->exchangeRate->isPositive()) {
                $this->add('A foreign-currency invoice with taxed lines needs a positive exchange rate to PLN.');
            } elseif ($i->exchangeRate->scale() > 6 && !$i->exchangeRate->equals($i->exchangeRate->roundTo(6))) {
                $this->add('The exchange rate allows at most 6 decimal places.');
            }
        }
        if ($i->currency === 'PLN' && $i->exchangeRate !== null) {
            $this->add('An exchange rate must not be given for PLN invoices.');
        }
    }

    private function payment(): void
    {
        $payment = $this->invoice->payment;
        if ($payment === null) {
            return;
        }

        if ($payment->paidOn !== null) {
            $this->date('Payment date', $payment->paidOn, '2016-07-01');
        }
        foreach ($payment->dueDates as $due) {
            $this->date('Payment due date', $due, '2016-07-01');
        }
        foreach ($payment->bankAccounts as $account) {
            if (preg_match('/^[A-Za-z0-9]{10,34}$/', $account) !== 1) {
                $this->add(\sprintf('Bank account "%s" must contain 10 to 34 letters or digits without spaces.', $account));
            }
        }
        if (\count($payment->dueDates) > 100 || \count($payment->bankAccounts) > 100) {
            $this->add('Too many payment due dates or bank accounts.');
        }
    }

    private function correction(): void
    {
        $i = $this->invoice;
        if ($i->type->isCorrection()) {
            if ($i->correction === null || $i->correction->correctedInvoices === []) {
                $this->add('A correction invoice needs the corrected invoice reference(s).');

                return;
            }
            if ($i->correction->reason !== null) {
                $this->text('Correction reason', $i->correction->reason, 256);
            }
            if (\count($i->correction->correctedInvoices) > 50_000) {
                $this->add('A correction can refer to at most 50,000 invoices.');
            }
            $this->collectiveCorrection();
            foreach ($i->correction->correctedInvoices as $index => $corrected) {
                $this->text(\sprintf('Corrected invoice %d number', $index + 1), $corrected->number, 256);
                $this->date(\sprintf('Corrected invoice %d date', $index + 1), $corrected->issueDate);
                if ($corrected->ksefNumber !== null && preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-(20[2-9]\d|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-[0-9A-F]{6}-?[0-9A-F]{6}-[0-9A-F]{2}$/', $corrected->ksefNumber) !== 1) {
                    $this->add(\sprintf('"%s" is not a valid KSeF number.', $corrected->ksefNumber));
                }
            }
        } elseif ($i->correction !== null) {
            $this->add('Correction details are only allowed on correction invoices.');
        }
    }

    private function collectiveCorrection(): void
    {
        $i = $this->invoice;
        $correction = $i->correction;
        if ($correction === null) {
            return;
        }
        if ($correction->period === null) {
            if ($correction->amounts !== []) {
                $this->add('Amounts per rate belong to collective corrections: give the period (Correction::$period) as well.');
            }

            return;
        }
        if ($i->type !== InvoiceType::Correction) {
            $this->add('Only a plain correction (KOR) can be a collective correction.');
        }
        $this->text('Correction period', $correction->period, 256);
        if ($correction->amounts === []) {
            $this->add('A collective correction needs the correction of the tax base and tax per rate (Correction::$amounts).');
        }
        foreach ($correction->amounts as $index => $amount) {
            $label = \sprintf('Collective correction amount %d (%s)', $index + 1, $amount->rate->value);
            if ($amount->rate->isTaxed() && $amount->vat === null) {
                $this->add($label . ' needs the correction of the tax.');
            }
            if (!$amount->rate->isTaxed() && $amount->vat !== null) {
                $this->add($label . ' is not taxed and takes no tax amount.');
            }
        }
    }

    private function kindSpecificRules(): void
    {
        $i = $this->invoice;

        if (!$i->type->isAdvance() && $i->advance !== null) {
            $this->add('Advance payment data is only allowed on advance invoices (ZAL, KOR_ZAL).');
        }
        if (!$i->type->isSettlement() && $i->settlement !== null) {
            $this->add('Settlement data is only allowed on settlement invoices (ROZ, KOR_ROZ).');
        }

        switch ($i->type) {
            case InvoiceType::Advance:
            case InvoiceType::AdvanceCorrection:
                if ($i->advance === null) {
                    $this->add('An advance invoice needs the advance payment (AdvancePayment).');

                    break;
                }
                if ($i->advance->paid->currency !== $i->currency) {
                    $this->add('The advance payment currency differs from the invoice currency.');
                }
                if ($i->type === InvoiceType::Advance && !$i->advance->paid->amount->isPositive()) {
                    $this->add('The advance payment must be positive.');
                }
                if ($i->type === InvoiceType::AdvanceCorrection && $i->advance->paid->amount->isZero()) {
                    $this->add('The change of the advance payment must not be zero; give the difference (negative when it decreases).');
                }
                $this->date('Advance payment date', $i->advance->receivedOn);
                if ($i->saleDate !== null) {
                    $this->add('An advance invoice takes its date from the payment; do not set a sale date.');
                }

                break;
            case InvoiceType::Settlement:
            case InvoiceType::SettlementCorrection:
                if ($i->settlement === null) {
                    $this->add('A settlement invoice needs Settlement data (advance invoices and the amount paid).');

                    break;
                }
                if (\count($i->settlement->advanceInvoices) > 100) {
                    $this->add('A settlement invoice can refer to at most 100 advance invoices.');
                }
                foreach ($i->settlement->advanceInvoices as $index => $reference) {
                    if ($reference->ksefNumber !== null) {
                        $error = KsefNumber::validate($reference->ksefNumber);
                        if ($error !== null) {
                            $this->add(\sprintf('Advance invoice %d: "%s" is not a valid KSeF number (%s).', $index + 1, $reference->ksefNumber, $error));
                        }
                    } elseif ($reference->number !== null) {
                        $this->text(\sprintf('Advance invoice %d number', $index + 1), $reference->number, 256);
                    }
                }
                $paid = $i->settlement->advancesPaid;
                if ($paid->currency !== $i->currency) {
                    $this->add('The amount paid in advances must be in the invoice currency.');
                }
                if ($i->type === InvoiceType::Settlement && ($paid->isNegative() || $paid->amount->roundTo(2)->compare($i->saleTotals()->gross()) > 0)) {
                    $this->add('The advances paid must be between zero and the invoice total.');
                }
                $this->advanceParts($paid);

                break;
            case InvoiceType::Simplified:
                if ($i->buyer->identifier->type !== BuyerIdentifierType::Nip) {
                    $this->add('A simplified invoice (UPR) identifies the buyer by NIP.');
                }
                $limit = match ($i->currency) {
                    'PLN' => '450',
                    'EUR' => '100',
                    default => null,
                };
                if ($limit === null) {
                    $this->add('A simplified invoice (UPR) can only be issued in PLN or EUR.');
                } elseif ($i->totals()->gross()->compare(Decimal::of($limit)) > 0) {
                    $this->add(\sprintf('A simplified invoice (UPR) cannot exceed %s %s.', $limit, $i->currency));
                }

                break;
            default:
                break;
        }
    }

    private function advanceParts(Money $paid): void
    {
        $settlement = $this->invoice->settlement;
        if ($settlement === null) {
            return;
        }

        if ($settlement->advanceAmounts !== []) {
            $sum = Decimal::of('0.00');
            foreach ($settlement->advanceAmounts as $part) {
                if ($part->gross->currency !== $this->invoice->currency) {
                    $this->add('The advance amounts must be in the invoice currency.');
                }
                $sum = $sum->add($part->gross->amount->roundTo(2));
            }
            if (!$sum->equals($paid->amount->roundTo(2))) {
                $this->add('The advance amounts per rate must add up to the amount paid in advances.');
            }
        } elseif (!$paid->amount->isZero() && $this->invoice->advanceParts() === []) {
            $this->add('Give the VAT rate of the advances (Settlement::$advanceRate) or split them by rate (Settlement::$advanceAmounts): the invoice has several rates.');
        }

        $buckets = [];
        foreach ($this->invoice->lines as $line) {
            $buckets[$line->vatRate->bucket()] = true;
        }
        foreach ($this->invoice->advanceParts() as $part) {
            if (!isset($buckets[$part->rate->bucket()])) {
                $this->add(\sprintf('The advances were taxed at %s, but no line of the invoice has that rate.', $part->rate->value));
            }
        }
    }

    private function amounts(): void
    {
        $limit = Decimal::of('9999999999999999.99');
        $totals = $this->invoice->totals();
        if ($totals->gross()->abs()->compare($limit) > 0) {
            $this->add('The invoice total exceeds the maximum amount supported by the schema.');
        }
    }

    private function partyExtras(): void
    {
        $i = $this->invoice;

        foreach (['Seller' => $i->seller->eori, 'Buyer' => $i->buyer->eori] as $label => $eori) {
            if ($eori !== null) {
                $this->text($label . ' EORI number', $eori, 240);
            }
        }
        if ($i->seller->vatPrefix !== null && preg_match('/^[A-Z]{2}$/', $i->seller->vatPrefix) !== 1) {
            $this->add('The seller VAT prefix must be a two-letter upper case code.');
        }
        if ($i->seller->correspondenceAddress !== null) {
            $this->party('Seller correspondence', $i->seller->name, $i->seller->correspondenceAddress, null, null);
        }
        if ($i->buyer->correspondenceAddress !== null) {
            $this->party('Buyer correspondence', $i->buyer->name, $i->buyer->correspondenceAddress, null, null);
        }
        if ($i->buyer->buyerKey !== null) {
            $this->text('Buyer key', $i->buyer->buyerKey, 32);
        }

        $correction = $i->correction;
        if ($correction === null) {
            return;
        }
        if ($correction->sellerBefore !== null) {
            $this->party('Seller before correction', $correction->sellerBefore->name, $correction->sellerBefore->address, null, null);
        }
        if ($correction->amountBefore !== null) {
            if (!$i->type->isAdvance() && !$i->type->isSettlement()) {
                $this->add('The amount before the correction (P_15ZK) belongs to corrections of advance (KOR_ZAL) and settlement (KOR_ROZ) invoices.');
            }
            if ($correction->amountBefore->currency !== $i->currency) {
                $this->add('The amount before the correction must be in the invoice currency.');
            }
        }
        if ($correction->exchangeRateBefore !== null && ($correction->amountBefore === null || !$correction->exchangeRateBefore->isPositive())) {
            $this->add('The exchange rate before the correction needs the amount before the correction and must be positive.');
        }
        if (\count($correction->buyersBefore) > 101) {
            $this->add('At most 101 buyers can be given for the state before the correction.');
        }
        foreach ($correction->buyersBefore as $index => $before) {
            $label = \sprintf('Buyer %d before correction', $index + 1);
            $this->party($label, $before->name, $before->address, null, null);
            $this->buyerIdentifier($before->identifier);
            if ($before->buyerKey === null) {
                $this->add($label . ' needs a buyer key that links it to the buyer data of the correction.');
            } else {
                $this->text($label . ' key', $before->buyerKey, 32);
            }
        }
    }

    private function newTransport(): void
    {
        $supply = $this->invoice->annotations->newTransport;
        if ($supply === null) {
            return;
        }
        if (\count($supply->vehicles) > 10_000) {
            $this->add('At most 10,000 new means of transport can be listed.');
        }
        foreach ($supply->vehicles as $index => $vehicle) {
            $label = \sprintf('New means of transport %d', $index + 1);
            $this->date($label . ' admission date', $vehicle->admittedOn);
            if ($vehicle->lineNumber < 1 || $vehicle->lineNumber > \count($this->invoice->lines)) {
                $this->add($label . ' refers to an invoice line that does not exist.');
            }
            foreach (['brand' => $vehicle->brand, 'model' => $vehicle->model, 'colour' => $vehicle->color, 'registration number' => $vehicle->registrationNumber, 'production year' => $vehicle->productionYear] as $field => $value) {
                if ($value !== null) {
                    $this->text($label . ' ' . $field, $value, 240);
                }
            }
            foreach ($vehicle->specific as $field => $value) {
                $this->text($label . ' ' . $field, $value, 240);
            }
            if (\count(array_intersect_key($vehicle->specific, ['P_22B1' => 1, 'P_22B2' => 1, 'P_22B3' => 1, 'P_22B4' => 1])) > 1) {
                $this->add($label . ' can carry only one of: VIN, body, chassis or frame number.');
            }
        }
    }

    private function extras(): void
    {
        $i = $this->invoice;

        if (\count($i->warehouseDocuments) > 1000) {
            $this->add('At most 1000 warehouse documents can be listed.');
        }
        foreach ($i->warehouseDocuments as $number) {
            $this->text('Warehouse document number', $number, 256);
        }
        if (\count($i->additionalInfo) > 10_000) {
            $this->add('At most 10,000 additional remarks are allowed.');
        }
        foreach ($i->additionalInfo as $index => $info) {
            $this->text(\sprintf('Remark %d key', $index + 1), $info->key, 256);
            $this->text(\sprintf('Remark %d value', $index + 1), $info->value, 256);
            if ($info->lineNumber !== null && ($info->lineNumber < 1 || $info->lineNumber > \count($i->lines))) {
                $this->add(\sprintf('Remark %d refers to line %d, which does not exist.', $index + 1, $info->lineNumber));
            }
        }

        $settlement = $i->additionalSettlement;
        if ($settlement !== null) {
            if (\count($settlement->charges) > 100 || \count($settlement->deductions) > 100) {
                $this->add('At most 100 charges and 100 deductions are allowed.');
            }
            foreach (['Charge' => $settlement->charges, 'Deduction' => $settlement->deductions] as $kind => $adjustments) {
                foreach ($adjustments as $index => $adjustment) {
                    $this->text(\sprintf('%s %d reason', $kind, $index + 1), $adjustment->reason, 256);
                    if ($adjustment->amount->currency !== $i->currency || !$adjustment->amount->amount->isPositive()) {
                        $this->add(\sprintf('%s %d must be a positive amount in the invoice currency.', $kind, $index + 1));
                    }
                }
            }
        }

        $payment = $i->payment;
        if ($payment !== null) {
            if ($payment->partialPayments !== [] && $payment->paidOn !== null) {
                $this->add('Give either the payment date (paid in full) or the partial payments, not both.');
            }
            if (\count($payment->partialPayments) > 100) {
                $this->add('At most 100 partial payments are allowed.');
            }
            foreach ($payment->partialPayments as $index => $part) {
                $this->date(\sprintf('Partial payment %d date', $index + 1), $part->paidOn, '2016-07-01');
                if ($part->amount->currency !== $i->currency || !$part->amount->amount->isPositive()) {
                    $this->add(\sprintf('Partial payment %d must be a positive amount in the invoice currency.', $index + 1));
                }
            }
            if ($payment->method !== null && $payment->otherMethod !== null) {
                $this->add('Give either a payment method or a description of another method, not both.');
            }
            if ($payment->otherMethod !== null) {
                $this->text('Payment method description', $payment->otherMethod, 256);
            }
            if (($payment->skontoConditions === null) !== ($payment->skontoAmount === null)) {
                $this->add('The early-payment discount needs both its conditions and its amount.');
            }
            if ($payment->skontoConditions !== null) {
                $this->text('Discount conditions', $payment->skontoConditions, 256);
                $this->text('Discount amount', (string) $payment->skontoAmount, 256);
            }
        }

        $terms = $i->terms;
        if ($terms !== null) {
            if (\count($terms->contracts) > 100 || \count($terms->orders) > 100 || \count($terms->batchNumbers) > 1000) {
                $this->add('Too many contracts, orders or batch numbers.');
            }
            foreach (['Contract' => $terms->contracts, 'Order' => $terms->orders] as $kind => $references) {
                foreach ($references as $index => $reference) {
                    if ($reference->number !== null) {
                        $this->text(\sprintf('%s %d number', $kind, $index + 1), $reference->number, 256);
                    }
                    if ($reference->date !== null) {
                        $this->date(\sprintf('%s %d date', $kind, $index + 1), $reference->date);
                    }
                }
            }
            foreach ($terms->batchNumbers as $batch) {
                $this->text('Batch number', $batch, 256);
            }
            if ($terms->deliveryTerms !== null) {
                $this->text('Delivery terms', $terms->deliveryTerms, 256);
            }
            if (($terms->contractualRate === null) !== ($terms->contractualCurrency === null)) {
                $this->add('The contractual exchange rate and the contractual currency go together.');
            }
            if ($terms->contractualRate !== null && !$terms->contractualRate->isPositive()) {
                $this->add('The contractual exchange rate must be positive.');
            }
            if ($terms->contractualCurrency !== null && preg_match('/^[A-Z]{3}$/', $terms->contractualCurrency) !== 1) {
                $this->add('The contractual currency must be a three-letter ISO 4217 code in upper case.');
            }
            if (\count($terms->transports) > 20) {
                $this->add('At most 20 transports can be described.');
            }
            foreach ($terms->transports as $index => $transport) {
                $this->transport(\sprintf('Transport %d', $index + 1), $transport);
            }
        }

        if ($i->attachment !== null) {
            $this->attachment($i->attachment);
        }
    }

    private function transport(string $label, Transport $transport): void
    {
        if (($transport->type === null) === ($transport->otherType === null)) {
            $this->add($label . ' needs either a transport type or a description of another type.');
        }
        if (($transport->cargo === null) === ($transport->otherCargo === null)) {
            $this->add($label . ' needs either a cargo type or a description of another cargo.');
        }
        foreach (['other type' => $transport->otherType, 'other cargo' => $transport->otherCargo] as $field => $value) {
            if ($value !== null) {
                $this->text($label . ' ' . $field, $value, 50);
            }
        }
        if ($transport->orderNumber !== null) {
            $this->text($label . ' order number', $transport->orderNumber, 240);
        }
        if ($transport->packagingUnit !== null) {
            $this->text($label . ' packaging unit', $transport->packagingUnit, 240);
        }
        if (\count($transport->via) > 20) {
            $this->add($label . ' can have at most 20 intermediate addresses.');
        }
        foreach (['from' => $transport->from, 'to' => $transport->to] as $field => $address) {
            if ($address !== null) {
                $this->party($label . ' ' . $field, 'x', $address, null, null);
            }
        }
        foreach ($transport->via as $index => $address) {
            $this->party(\sprintf('%s via %d', $label, $index + 1), 'x', $address, null, null);
        }
        if ($transport->carrier !== null) {
            $this->party($label . ' carrier', $transport->carrier->name, $transport->carrier->address, null, null);
            $this->buyerIdentifier($transport->carrier->identifier);
        }
        foreach (['start' => $transport->startsAt, 'end' => $transport->endsAt] as $field => $time) {
            if ($time !== null) {
                $this->date($label . ' ' . $field, $time, '2021-10-01');
            }
        }
        if ($transport->startsAt !== null && $transport->endsAt !== null && $transport->endsAt < $transport->startsAt) {
            $this->add($label . ' ends before it starts.');
        }
    }

    private function attachment(Attachment $attachment): void
    {
        if (\count($attachment->blocks) > 1000) {
            $this->add('An attachment can have at most 1000 data blocks.');
        }
        foreach ($attachment->blocks as $b => $block) {
            $label = \sprintf('Attachment block %d', $b + 1);
            if ($block->header !== null) {
                $this->text($label . ' header', $block->header, 512);
            }
            if ($block->metadata === [] || \count($block->metadata) > 1000) {
                $this->add($label . ' needs 1 to 1000 metadata entries (key/value descriptions).');
            }
            if (\count($block->paragraphs) > 10) {
                $this->add($label . ' can have at most 10 paragraphs.');
            }
            foreach ($block->paragraphs as $paragraph) {
                $this->text($label . ' paragraph', $paragraph, 512);
            }
            foreach ($block->metadata as $key => $value) {
                $this->text($label . ' metadata key', (string) $key, 256);
                $this->text($label . ' metadata value', $value, 256);
            }
            foreach ($block->tables as $t => $table) {
                $tableLabel = \sprintf('%s table %d', $label, $t + 1);
                $width = \count($table->columns);
                if ($width < 1 || $width > 20) {
                    $this->add($tableLabel . ' needs 1 to 20 columns.');
                }
                foreach ($table->columns as $column) {
                    $this->text($tableLabel . ' column name', $column->name, 256);
                }
                if (\count($table->rows) > 1000) {
                    $this->add($tableLabel . ' can have at most 1000 rows.');
                }
                foreach ($table->rows as $r => $row) {
                    if (\count($row) !== $width) {
                        $this->add(\sprintf('%s row %d has %d cells for %d columns.', $tableLabel, $r + 1, \count($row), $width));
                    }
                    foreach ($row as $cell) {
                        $this->cell($tableLabel, $cell);
                    }
                }
                if ($table->totals !== null) {
                    if (\count($table->totals) !== $width) {
                        $this->add(\sprintf('%s summary has %d cells for %d columns.', $tableLabel, \count($table->totals), $width));
                    }
                    foreach ($table->totals as $cell) {
                        $this->cell($tableLabel, $cell);
                    }
                }
                if ($table->description !== null) {
                    $this->text($tableLabel . ' description', $table->description, 512);
                }
                foreach ($table->metadata as $key => $value) {
                    $this->text($tableLabel . ' metadata key', (string) $key, 256);
                    $this->text($tableLabel . ' metadata value', $value, 256);
                }
            }
        }
    }

    private function cell(string $label, string $value): void
    {
        if (mb_strlen($value, 'UTF-8') > 256) {
            $this->add($label . ' has a cell longer than 256 characters.');
        }
    }

    private function authorizedEntity(): void
    {
        $entity = $this->invoice->authorizedEntity;
        if ($entity === null) {
            return;
        }
        $this->party('Authorized entity', $entity->name, $entity->address, $entity->email, $entity->phone);
        if ($entity->correspondenceAddress !== null) {
            $this->party('Authorized entity correspondence', $entity->name, $entity->correspondenceAddress, null, null);
        }
    }

    private function thirdParties(): void
    {
        $parties = $this->invoice->thirdParties;
        if (\count($parties) > 100) {
            $this->add('An invoice can name at most 100 additional parties.');
        }
        foreach ($parties as $index => $party) {
            $label = \sprintf('Additional party %d', $index + 1);
            $this->party($label, $party->name, $party->address, $party->email, $party->phone);
            if ($party->correspondenceAddress !== null) {
                $this->party($label . ' correspondence', $party->name, $party->correspondenceAddress, null, null);
            }
            $this->buyerIdentifier($party->identifier);
            if ($party->otherRole !== null) {
                $this->text($label . ' role description', $party->otherRole, 256);
            }
            if ($party->share !== null && ($party->share->isNegative() || $party->share->compare(Decimal::of('100')) > 0)) {
                $this->add($label . ': the share must be between 0 and 100 percent.');
            }
            if ($party->customerNumber !== null) {
                $this->text($label . ' customer number', $party->customerNumber, 256);
            }
        }
    }

    private function buyerIdentifier(BuyerIdentifier $identifier): void
    {
        switch ($identifier->type) {
            case BuyerIdentifierType::EuVat:
                if (preg_match('/^[A-Z]{2}$/', (string) $identifier->countryCode) !== 1) {
                    $this->add('The EU VAT country prefix must be a two-letter upper case code.');
                }
                if (preg_match('/^[A-Z0-9+*]{1,12}$/', (string) $identifier->value) !== 1) {
                    $this->add('The EU VAT number must have 1 to 12 characters (digits, upper case letters, "+" or "*").');
                }
                break;
            case BuyerIdentifierType::Foreign:
                $this->text('Foreign tax number', (string) $identifier->value, 50);
                if ($identifier->countryCode !== null && preg_match('/^[A-Z]{2}$/', $identifier->countryCode) !== 1) {
                    $this->add('The tax number country must be a two-letter upper case code.');
                }
                break;
            default:
                break;
        }
    }

    private function party(string $label, string $name, ?Address $address, ?string $email, ?string $phone): void
    {
        $this->text($label . ' name', $name, 512);
        if ($address !== null) {
            if (preg_match('/^[A-Z]{2}$/', $address->countryCode) !== 1) {
                $this->add(\sprintf('%s address: the country code must be a two-letter upper case code.', $label));
            }
            $this->text($label . ' address line 1', $address->line1, 512);
            if ($address->line2 !== null) {
                $this->text($label . ' address line 2', $address->line2, 512);
            }
            if ($address->gln !== null && preg_match('/^\d{1,13}$/', $address->gln) !== 1) {
                $this->add($label . ' address: the GLN must have up to 13 digits.');
            }
        }
        if ($email !== null && (\strlen($email) < 3 || \strlen($email) > 255 || preg_match('/^.+@.+$/', $email) !== 1)) {
            $this->add(\sprintf('%s e-mail "%s" is not valid.', $label, $email));
        }
        if ($phone !== null) {
            $this->text($label . ' phone', $phone, 16);
        }
    }

    private function text(string $label, string $value, int $maxLength): void
    {
        if (trim($value) === '') {
            $this->add($label . ' must not be empty.');

            return;
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $this->add(\sprintf('%s is longer than %d characters.', $label, $maxLength));
        }
        if (preg_match(self::FORBIDDEN_CHARACTERS, $value) === 1) {
            $this->add($label . ' contains characters that KSeF rejects (control characters or Unicode non-characters).');
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $this->add($label . ' is not valid UTF-8.');
        }
    }

    private function date(string $label, DateTimeImmutable $date, string $min = '2006-01-01'): void
    {
        $day = $date->format('Y-m-d');
        if ($day < $min || $day > '2050-01-01') {
            $this->add(\sprintf('%s %s must be between %s and 2050-01-01.', $label, $day, $min));
        }
    }

    private function add(string $violation): void
    {
        $this->violations[] = $violation;
    }
}
