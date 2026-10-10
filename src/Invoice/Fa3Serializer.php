<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Serializes an {@see Invoice} into an FA(3) XML document.
 *
 * The document is built with DOM (never by string concatenation), so escaping, namespaces and
 * encoding are handled by libxml. Element order follows the schema sequence. Output is UTF-8
 * without a byte order mark and contains no processing instructions, as KSeF requires.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class Fa3Serializer
{
    private const NS = FormCode::FA3_NAMESPACE;

    /**
     * @throws SerializationException
     */
    public function serialize(Invoice $invoice, DateTimeImmutable $createdAt): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $root = $document->createElementNS(self::NS, 'Faktura');
        $document->appendChild($root);

        $this->header($document, $root, $createdAt);
        $this->seller($document, $root, $invoice->seller);
        $this->buyer($document, $root, $invoice->buyer);
        foreach ($invoice->thirdParties as $party) {
            $this->thirdParty($document, $root, $party);
        }
        if ($invoice->authorizedEntity !== null) {
            $this->authorizedEntity($document, $root, $invoice->authorizedEntity);
        }
        $this->invoiceBody($document, $root, $invoice);

        if ($invoice->footer !== null) {
            $footer = $this->el($document, $root, 'Stopka');
            $info = $this->el($document, $footer, 'Informacje');
            $this->el($document, $info, 'StopkaFaktury', $invoice->footer);
        }
        if ($invoice->attachment !== null) {
            $this->attachment($document, $root, $invoice->attachment);
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SerializationException('The invoice XML could not be generated.');
        }

        return $xml;
    }

    private function header(DOMDocument $d, DOMElement $root, DateTimeImmutable $createdAt): void
    {
        $header = $this->el($d, $root, 'Naglowek');
        $code = $this->el($d, $header, 'KodFormularza', 'FA');
        $code->setAttribute('kodSystemowy', 'FA (3)');
        $code->setAttribute('wersjaSchemy', '1-0E');
        $this->el($d, $header, 'WariantFormularza', '3');
        $this->el($d, $header, 'DataWytworzeniaFa', $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->el($d, $header, 'SystemInfo', 'ksef-php');
    }

    private function seller(DOMDocument $d, DOMElement $root, Seller $seller): void
    {
        $node = $this->el($d, $root, 'Podmiot1');
        if ($seller->vatPrefix !== null) {
            $this->el($d, $node, 'PrefiksPodatnika', $seller->vatPrefix);
        }
        if ($seller->eori !== null) {
            $this->el($d, $node, 'NrEORI', $seller->eori);
        }
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->el($d, $id, 'NIP', $seller->nip->value);
        $this->el($d, $id, 'Nazwa', $seller->name);
        $this->address($d, $node, 'Adres', $seller->address);
        if ($seller->correspondenceAddress !== null) {
            $this->address($d, $node, 'AdresKoresp', $seller->correspondenceAddress);
        }
        $this->contact($d, $node, $seller->email, $seller->phone);
    }

    private function buyer(DOMDocument $d, DOMElement $root, Buyer $buyer, string $name = 'Podmiot2', bool $full = true): void
    {
        $node = $this->el($d, $root, $name);
        if ($full && $buyer->eori !== null) {
            $this->el($d, $node, 'NrEORI', $buyer->eori);
        }
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->identifier($d, $id, $buyer->identifier);
        $this->el($d, $id, 'Nazwa', $buyer->name);

        if ($buyer->address !== null) {
            $this->address($d, $node, 'Adres', $buyer->address);
        }
        if (!$full) {
            if ($buyer->buyerKey !== null) {
                $this->el($d, $node, 'IDNabywcy', $buyer->buyerKey);
            }

            return;
        }
        if ($buyer->correspondenceAddress !== null) {
            $this->address($d, $node, 'AdresKoresp', $buyer->correspondenceAddress);
        }
        $this->contact($d, $node, $buyer->email, $buyer->phone);
        if ($buyer->customerNumber !== null) {
            $this->el($d, $node, 'NrKlienta', $buyer->customerNumber);
        }
        if ($buyer->buyerKey !== null) {
            $this->el($d, $node, 'IDNabywcy', $buyer->buyerKey);
        }
        $this->el($d, $node, 'JST', $buyer->localGovernmentSubunit ? '1' : '2');
        $this->el($d, $node, 'GV', $buyer->vatGroupMember ? '1' : '2');
    }

    /** `Podmiot1K`: the seller data as it was on the corrected invoice. */
    private function sellerBefore(DOMDocument $d, DOMElement $root, Seller $seller): void
    {
        $node = $this->el($d, $root, 'Podmiot1K');
        if ($seller->vatPrefix !== null) {
            $this->el($d, $node, 'PrefiksPodatnika', $seller->vatPrefix);
        }
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->el($d, $id, 'NIP', $seller->nip->value);
        $this->el($d, $id, 'Nazwa', $seller->name);
        $this->address($d, $node, 'Adres', $seller->address);
    }

    private function thirdParty(DOMDocument $d, DOMElement $root, ThirdParty $party): void
    {
        $node = $this->el($d, $root, 'Podmiot3');
        if ($party->eori !== null) {
            $this->el($d, $node, 'NrEORI', $party->eori);
        }
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->identifier($d, $id, $party->identifier);
        $this->el($d, $id, 'Nazwa', $party->name);
        if ($party->address !== null) {
            $this->address($d, $node, 'Adres', $party->address);
        }
        if ($party->correspondenceAddress !== null) {
            $this->address($d, $node, 'AdresKoresp', $party->correspondenceAddress);
        }
        $this->contact($d, $node, $party->email, $party->phone);
        if ($party->role !== null) {
            $this->el($d, $node, 'Rola', $party->role->value);
        } else {
            $this->el($d, $node, 'RolaInna', '1');
            $this->el($d, $node, 'OpisRoli', (string) $party->otherRole);
        }
        if ($party->share !== null) {
            $this->el($d, $node, 'Udzial', $party->share->toTrimmedString());
        }
        if ($party->customerNumber !== null) {
            $this->el($d, $node, 'NrKlienta', $party->customerNumber);
        }
    }

    private function authorizedEntity(DOMDocument $d, DOMElement $root, AuthorizedEntity $entity): void
    {
        $node = $this->el($d, $root, 'PodmiotUpowazniony');
        if ($entity->eori !== null) {
            $this->el($d, $node, 'NrEORI', $entity->eori);
        }
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->el($d, $id, 'NIP', $entity->nip->value);
        $this->el($d, $id, 'Nazwa', $entity->name);
        $this->address($d, $node, 'Adres', $entity->address);
        if ($entity->correspondenceAddress !== null) {
            $this->address($d, $node, 'AdresKoresp', $entity->correspondenceAddress);
        }
        $this->contact($d, $node, $entity->email, $entity->phone, 'EmailPU', 'TelefonPU');
        $this->el($d, $node, 'RolaPU', $entity->role->value);
    }

    private function identifier(DOMDocument $d, DOMElement $id, BuyerIdentifier $identifier): void
    {
        switch ($identifier->type) {
            case BuyerIdentifierType::Nip:
                $this->el($d, $id, 'NIP', (string) $identifier->value);
                break;
            case BuyerIdentifierType::EuVat:
                $this->el($d, $id, 'KodUE', (string) $identifier->countryCode);
                $this->el($d, $id, 'NrVatUE', (string) $identifier->value);
                break;
            case BuyerIdentifierType::Foreign:
                if ($identifier->countryCode !== null) {
                    $this->el($d, $id, 'KodKraju', $identifier->countryCode);
                }
                $this->el($d, $id, 'NrID', (string) $identifier->value);
                break;
            case BuyerIdentifierType::None:
                $this->el($d, $id, 'BrakID', '1');
                break;
        }
    }

    private function invoiceBody(DOMDocument $d, DOMElement $root, Invoice $invoice): void
    {
        $fa = $this->el($d, $root, 'Fa');
        $this->el($d, $fa, 'KodWaluty', $invoice->currency);
        $this->el($d, $fa, 'P_1', $invoice->issueDate->format('Y-m-d'));
        if ($invoice->issuePlace !== null) {
            $this->el($d, $fa, 'P_1M', $invoice->issuePlace);
        }
        $this->el($d, $fa, 'P_2', $invoice->number);
        foreach ($invoice->warehouseDocuments as $wz) {
            $this->el($d, $fa, 'WZ', $wz);
        }
        $p6 = $invoice->type->isAdvance() ? $invoice->advance?->receivedOn : $invoice->saleDate;
        if ($p6 !== null) {
            $this->el($d, $fa, 'P_6', $p6->format('Y-m-d'));
        }

        $totals = $invoice->totals();
        // Buckets are produced in schema order; only the taxed ones carry P_14_x fields.
        foreach ($totals->buckets as $bucket) {
            $this->el($d, $fa, 'P_13_' . $bucket->key, $bucket->net->toString(2));
            if ($bucket->vat !== null) {
                $this->el($d, $fa, 'P_14_' . $bucket->key, $bucket->vat->toString(2));
                if ($bucket->vatPln !== null) {
                    $this->el($d, $fa, 'P_14_' . $bucket->key . 'W', $bucket->vatPln->toString(2));
                }
            }
        }
        $this->el($d, $fa, 'P_15', $invoice->amountDue()->toString(2));
        if ($invoice->exchangeRate !== null) {
            $this->el($d, $fa, 'KursWalutyZ', $invoice->exchangeRate->toTrimmedString(2));
        }

        $this->annotations($d, $fa, $invoice);
        $this->el($d, $fa, 'RodzajFaktury', $invoice->type->value);
        if ($invoice->correction !== null) {
            $this->correction($d, $fa, $invoice->correction);
        }

        if ($invoice->annotations->invoiceUnderArt109) {
            $this->el($d, $fa, 'FP', '1');
        }
        if ($invoice->annotations->relatedParties) {
            $this->el($d, $fa, 'TP', '1');
        }
        foreach ($invoice->additionalInfo as $info) {
            $node = $this->el($d, $fa, 'DodatkowyOpis');
            if ($info->lineNumber !== null) {
                $this->el($d, $node, 'NrWiersza', (string) $info->lineNumber);
            }
            $this->el($d, $node, 'Klucz', $info->key);
            $this->el($d, $node, 'Wartosc', $info->value);
        }

        if ($invoice->settlement !== null) {
            foreach ($invoice->settlement->advanceInvoices as $reference) {
                $node = $this->el($d, $fa, 'FakturaZaliczkowa');
                if ($reference->ksefNumber !== null) {
                    $this->el($d, $node, 'NrKSeFFaZaliczkowej', $reference->ksefNumber);
                } else {
                    $this->el($d, $node, 'NrKSeFZN', '1');
                    $this->el($d, $node, 'NrFaZaliczkowej', (string) $reference->number);
                }
            }
        }

        if ($invoice->annotations->exciseRefund) {
            $this->el($d, $fa, 'ZwrotAkcyzy', '1');
        }

        if (!$invoice->type->isAdvance()) {
            foreach ($invoice->lines as $index => $line) {
                $this->line($d, $fa, $index + 1, $line);
            }
        }

        if ($invoice->additionalSettlement !== null) {
            $this->additionalSettlement($d, $fa, $invoice);
        }
        if ($invoice->payment !== null) {
            $this->payment($d, $fa, $invoice->payment, $invoice->amountDue());
        }
        if ($invoice->terms !== null) {
            $this->terms($d, $fa, $invoice->terms);
        }
        if ($invoice->type->isAdvance()) {
            $this->order($d, $fa, $invoice);
        }
    }

    private function annotations(DOMDocument $d, DOMElement $fa, Invoice $invoice): void
    {
        $a = $invoice->annotations;
        $node = $this->el($d, $fa, 'Adnotacje');
        $this->el($d, $node, 'P_16', $a->cashAccounting ? '1' : '2');
        $this->el($d, $node, 'P_17', $a->selfBilling ? '1' : '2');
        $this->el($d, $node, 'P_18', $invoice->hasLinesWith(VatRate::ReverseCharge) ? '1' : '2');
        $this->el($d, $node, 'P_18A', $a->splitPayment ? '1' : '2');

        $exemption = $this->el($d, $node, 'Zwolnienie');
        if ($invoice->hasLinesWith(VatRate::Exempt)) {
            $this->el($d, $exemption, 'P_19', '1');
            $this->el($d, $exemption, 'P_19A', (string) $a->exemptionBasis);
        } else {
            $this->el($d, $exemption, 'P_19N', '1');
        }

        $transport = $this->el($d, $node, 'NoweSrodkiTransportu');
        if ($a->newTransport === null) {
            $this->el($d, $transport, 'P_22N', '1');
        } else {
            $this->el($d, $transport, 'P_22', '1');
            $this->el($d, $transport, 'P_42_5', $a->newTransport->obligationUnderArt42 ? '1' : '2');
            foreach ($a->newTransport->vehicles as $vehicle) {
                $node22 = $this->el($d, $transport, 'NowySrodekTransportu');
                $this->el($d, $node22, 'P_22A', $vehicle->admittedOn->format('Y-m-d'));
                $this->el($d, $node22, 'P_NrWierszaNST', (string) $vehicle->lineNumber);
                foreach (['P_22BMK' => $vehicle->brand, 'P_22BMD' => $vehicle->model, 'P_22BK' => $vehicle->color, 'P_22BNR' => $vehicle->registrationNumber, 'P_22BRP' => $vehicle->productionYear] as $field => $value) {
                    if ($value !== null) {
                        $this->el($d, $node22, $field, $value);
                    }
                }
                foreach ($vehicle->specific as $field => $value) {
                    $this->el($d, $node22, $field, $value);
                }
            }
        }
        $this->el($d, $node, 'P_23', $a->triangular ? '1' : '2');
        $margin = $this->el($d, $node, 'PMarzy');
        if ($a->marginScheme !== null) {
            $this->el($d, $margin, 'P_PMarzy', '1');
            $this->el($d, $margin, $a->marginScheme->value, '1');
        } else {
            $this->el($d, $margin, 'P_PMarzyN', '1');
        }
    }

    private function correction(DOMDocument $d, DOMElement $fa, Correction $correction): void
    {
        if ($correction->reason !== null) {
            $this->el($d, $fa, 'PrzyczynaKorekty', $correction->reason);
        }
        $this->el($d, $fa, 'TypKorekty', (string) $correction->type->value);
        foreach ($correction->correctedInvoices as $corrected) {
            $node = $this->el($d, $fa, 'DaneFaKorygowanej');
            $this->el($d, $node, 'DataWystFaKorygowanej', $corrected->issueDate->format('Y-m-d'));
            $this->el($d, $node, 'NrFaKorygowanej', $corrected->number);
            if ($corrected->ksefNumber !== null) {
                $this->el($d, $node, 'NrKSeF', '1');
                $this->el($d, $node, 'NrKSeFFaKorygowanej', $corrected->ksefNumber);
            } else {
                $this->el($d, $node, 'NrKSeFN', '1');
            }
        }
        if ($correction->sellerBefore !== null) {
            $this->sellerBefore($d, $fa, $correction->sellerBefore);
        }
        foreach ($correction->buyersBefore as $before) {
            $this->buyer($d, $fa, $before, 'Podmiot2K', false);
        }
        if ($correction->amountBefore !== null) {
            $this->el($d, $fa, 'P_15ZK', $correction->amountBefore->amount->roundTo(2)->toString(2));
            if ($correction->exchangeRateBefore !== null) {
                $this->el($d, $fa, 'KursWalutyZK', $correction->exchangeRateBefore->toTrimmedString(2));
            }
        }
    }

    private function line(DOMDocument $d, DOMElement $fa, int $number, InvoiceLine $line): void
    {
        $node = $this->el($d, $fa, 'FaWiersz');
        $this->el($d, $node, 'NrWierszaFa', (string) $number);
        if ($line->deliveryDate !== null) {
            $this->el($d, $node, 'P_6A', $line->deliveryDate->format('Y-m-d'));
        }
        $this->el($d, $node, 'P_7', $line->name);
        if ($line->internalCode !== null) {
            $this->el($d, $node, 'Indeks', $line->internalCode);
        }
        if ($line->gtin !== null) {
            $this->el($d, $node, 'GTIN', $line->gtin);
        }
        if ($line->pkwiu !== null) {
            $this->el($d, $node, 'PKWiU', $line->pkwiu);
        }
        if ($line->cn !== null) {
            $this->el($d, $node, 'CN', $line->cn);
        }
        if ($line->unit !== null) {
            $this->el($d, $node, 'P_8A', $line->unit);
        }
        $this->el($d, $node, 'P_8B', $line->quantity->toTrimmedString());
        $this->el($d, $node, 'P_9A', $line->unitNetPrice->amount->toTrimmedString(2));
        if ($line->discount !== null) {
            $this->el($d, $node, 'P_10', $line->discount->amount->toTrimmedString(2));
        }
        $this->el($d, $node, 'P_11', $line->netAmount()->toString(2));
        $this->el($d, $node, 'P_12', $line->vatRate->value);
        if ($line->excise !== null) {
            $this->el($d, $node, 'KwotaAkcyzy', $line->excise->amount->toString(2));
        }
        if ($line->gtu !== null) {
            $this->el($d, $node, 'GTU', $line->gtu->value);
        }
        if ($line->procedure !== null) {
            $this->el($d, $node, 'Procedura', $line->procedure->value);
        }
        if ($line->state === LineState::Before) {
            $this->el($d, $node, 'StanPrzed', '1');
        }
    }

    /** `Zamowienie`: the order or contract an advance invoice refers to, in the invoice currency. */
    private function order(DOMDocument $d, DOMElement $fa, Invoice $invoice): void
    {
        $order = $this->el($d, $fa, 'Zamowienie');
        $this->el($d, $order, 'WartoscZamowienia', $invoice->orderValue()->toString(2));
        foreach ($invoice->lines as $index => $line) {
            $row = $this->el($d, $order, 'ZamowienieWiersz');
            $this->el($d, $row, 'NrWierszaZam', (string) ($index + 1));
            $this->el($d, $row, 'P_7Z', $line->name);
            if ($line->unit !== null) {
                $this->el($d, $row, 'P_8AZ', $line->unit);
            }
            $this->el($d, $row, 'P_8BZ', $line->quantity->toTrimmedString());
            $this->el($d, $row, 'P_9AZ', $line->unitNetPrice->amount->toTrimmedString(2));
            $net = $line->netAmount();
            $this->el($d, $row, 'P_11NettoZ', $net->toString(2));
            $percentage = $line->vatRate->percentage();
            $this->el($d, $row, 'P_11VatZ', ($percentage !== null ? $net->percent($percentage)->roundTo(2) : Decimal::of('0.00'))->toString(2));
            $this->el($d, $row, 'P_12Z', $line->vatRate->value);
            if ($line->state === LineState::Before) {
                $this->el($d, $row, 'StanPrzedZ', '1');
            }
        }
    }

    private function payment(DOMDocument $d, DOMElement $fa, Payment $payment, Decimal $invoiceTotal): void
    {
        $node = $this->el($d, $fa, 'Platnosc');
        if ($payment->partialPayments !== []) {
            $this->el($d, $node, 'ZnacznikZaplatyCzesciowej', $payment->partialPaymentsComplete($invoiceTotal) ? '2' : '1');
            foreach ($payment->partialPayments as $part) {
                $row = $this->el($d, $node, 'ZaplataCzesciowa');
                $this->el($d, $row, 'KwotaZaplatyCzesciowej', $part->amount->amount->roundTo(2)->toString(2));
                $this->el($d, $row, 'DataZaplatyCzesciowej', $part->paidOn->format('Y-m-d'));
                if ($part->method !== null) {
                    $this->el($d, $row, 'FormaPlatnosci', (string) $part->method->value);
                }
            }
        } elseif ($payment->paidOn !== null) {
            $this->el($d, $node, 'Zaplacono', '1');
            $this->el($d, $node, 'DataZaplaty', $payment->paidOn->format('Y-m-d'));
        }
        foreach ($payment->dueDates as $due) {
            $this->el($d, $this->el($d, $node, 'TerminPlatnosci'), 'Termin', $due->format('Y-m-d'));
        }
        if ($payment->method !== null) {
            $this->el($d, $node, 'FormaPlatnosci', (string) $payment->method->value);
        } elseif ($payment->otherMethod !== null) {
            $this->el($d, $node, 'PlatnoscInna', '1');
            $this->el($d, $node, 'OpisPlatnosci', $payment->otherMethod);
        }
        foreach ($payment->bankAccounts as $account) {
            $this->el($d, $this->el($d, $node, 'RachunekBankowy'), 'NrRB', $account);
        }
        if ($payment->skontoConditions !== null && $payment->skontoAmount !== null) {
            $skonto = $this->el($d, $node, 'Skonto');
            $this->el($d, $skonto, 'WarunkiSkonta', $payment->skontoConditions);
            $this->el($d, $skonto, 'WysokoscSkonta', $payment->skontoAmount);
        }
    }

    private function additionalSettlement(DOMDocument $d, DOMElement $fa, Invoice $invoice): void
    {
        $settlement = $invoice->additionalSettlement;
        if ($settlement === null) {
            return;
        }
        $node = $this->el($d, $fa, 'Rozliczenie');
        foreach ($settlement->charges as $charge) {
            $row = $this->el($d, $node, 'Obciazenia');
            $this->el($d, $row, 'Kwota', $charge->amount->amount->roundTo(2)->toString(2));
            $this->el($d, $row, 'Powod', $charge->reason);
        }
        if ($settlement->charges !== []) {
            $this->el($d, $node, 'SumaObciazen', $settlement->totalCharges()->toString(2));
        }
        foreach ($settlement->deductions as $deduction) {
            $row = $this->el($d, $node, 'Odliczenia');
            $this->el($d, $row, 'Kwota', $deduction->amount->amount->roundTo(2)->toString(2));
            $this->el($d, $row, 'Powod', $deduction->reason);
        }
        if ($settlement->deductions !== []) {
            $this->el($d, $node, 'SumaOdliczen', $settlement->totalDeductions()->toString(2));
        }
        $due = $invoice->amountDue()->add($settlement->totalCharges())->subtract($settlement->totalDeductions());
        if ($due->isNegative()) {
            $this->el($d, $node, 'DoRozliczenia', $due->abs()->toString(2));
        } else {
            $this->el($d, $node, 'DoZaplaty', $due->toString(2));
        }
    }

    private function terms(DOMDocument $d, DOMElement $fa, TransactionTerms $terms): void
    {
        $node = $this->el($d, $fa, 'WarunkiTransakcji');
        foreach ($terms->contracts as $contract) {
            $row = $this->el($d, $node, 'Umowy');
            if ($contract->date !== null) {
                $this->el($d, $row, 'DataUmowy', $contract->date->format('Y-m-d'));
            }
            if ($contract->number !== null) {
                $this->el($d, $row, 'NrUmowy', $contract->number);
            }
        }
        foreach ($terms->orders as $order) {
            $row = $this->el($d, $node, 'Zamowienia');
            if ($order->date !== null) {
                $this->el($d, $row, 'DataZamowienia', $order->date->format('Y-m-d'));
            }
            if ($order->number !== null) {
                $this->el($d, $row, 'NrZamowienia', $order->number);
            }
        }
        foreach ($terms->batchNumbers as $batch) {
            $this->el($d, $node, 'NrPartiiTowaru', $batch);
        }
        if ($terms->deliveryTerms !== null) {
            $this->el($d, $node, 'WarunkiDostawy', $terms->deliveryTerms);
        }
        if ($terms->contractualRate !== null && $terms->contractualCurrency !== null) {
            $this->el($d, $node, 'KursUmowny', $terms->contractualRate->toTrimmedString(2));
            $this->el($d, $node, 'WalutaUmowna', $terms->contractualCurrency);
        }
        foreach ($terms->transports as $transport) {
            $this->transport($d, $node, $transport);
        }
        if ($terms->intermediary) {
            $this->el($d, $node, 'PodmiotPosredniczacy', '1');
        }
    }

    private function transport(DOMDocument $d, DOMElement $parent, Transport $transport): void
    {
        $node = $this->el($d, $parent, 'Transport');
        if ($transport->type !== null) {
            $this->el($d, $node, 'RodzajTransportu', (string) $transport->type->value);
        } else {
            $this->el($d, $node, 'TransportInny', '1');
            $this->el($d, $node, 'OpisInnegoTransportu', (string) $transport->otherType);
        }
        if ($transport->carrier !== null) {
            $carrier = $this->el($d, $node, 'Przewoznik');
            $id = $this->el($d, $carrier, 'DaneIdentyfikacyjne');
            $this->identifier($d, $id, $transport->carrier->identifier);
            $this->el($d, $id, 'Nazwa', $transport->carrier->name);
            $this->address($d, $carrier, 'AdresPrzewoznika', $transport->carrier->address);
        }
        if ($transport->orderNumber !== null) {
            $this->el($d, $node, 'NrZleceniaTransportu', $transport->orderNumber);
        }
        if ($transport->cargo !== null) {
            $this->el($d, $node, 'OpisLadunku', (string) $transport->cargo->value);
        } else {
            $this->el($d, $node, 'LadunekInny', '1');
            $this->el($d, $node, 'OpisInnegoLadunku', (string) $transport->otherCargo);
        }
        if ($transport->packagingUnit !== null) {
            $this->el($d, $node, 'JednostkaOpakowania', $transport->packagingUnit);
        }
        if ($transport->startsAt !== null) {
            $this->el($d, $node, 'DataGodzRozpTransportu', $transport->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        }
        if ($transport->endsAt !== null) {
            $this->el($d, $node, 'DataGodzZakTransportu', $transport->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        }
        if ($transport->from !== null) {
            $this->address($d, $node, 'WysylkaZ', $transport->from);
        }
        foreach ($transport->via as $via) {
            $this->address($d, $node, 'WysylkaPrzez', $via);
        }
        if ($transport->to !== null) {
            $this->address($d, $node, 'WysylkaDo', $transport->to);
        }
    }

    private function attachment(DOMDocument $d, DOMElement $root, Attachment $attachment): void
    {
        $node = $this->el($d, $root, 'Zalacznik');
        foreach ($attachment->blocks as $block) {
            $b = $this->el($d, $node, 'BlokDanych');
            if ($block->header !== null) {
                $this->el($d, $b, 'ZNaglowek', $block->header);
            }
            foreach ($block->metadata as $key => $value) {
                $meta = $this->el($d, $b, 'MetaDane');
                $this->el($d, $meta, 'ZKlucz', (string) $key);
                $this->el($d, $meta, 'ZWartosc', $value);
            }
            if ($block->paragraphs !== []) {
                $text = $this->el($d, $b, 'Tekst');
                foreach ($block->paragraphs as $paragraph) {
                    $this->el($d, $text, 'Akapit', $paragraph);
                }
            }
            foreach ($block->tables as $table) {
                $t = $this->el($d, $b, 'Tabela');
                foreach ($table->metadata as $key => $value) {
                    $meta = $this->el($d, $t, 'TMetaDane');
                    $this->el($d, $meta, 'TKlucz', (string) $key);
                    $this->el($d, $meta, 'TWartosc', $value);
                }
                if ($table->description !== null) {
                    $this->el($d, $t, 'Opis', $table->description);
                }
                $header = $this->el($d, $t, 'TNaglowek');
                foreach ($table->columns as $column) {
                    $col = $this->el($d, $header, 'Kol');
                    $col->setAttribute('Typ', $column->type->value);
                    $this->el($d, $col, 'NKom', $column->name);
                }
                foreach ($table->rows as $row) {
                    $r = $this->el($d, $t, 'Wiersz');
                    foreach ($row as $cell) {
                        $this->el($d, $r, 'WKom', $cell);
                    }
                }
                if ($table->totals !== null) {
                    $sum = $this->el($d, $t, 'Suma');
                    foreach ($table->totals as $cell) {
                        $this->el($d, $sum, 'SKom', $cell);
                    }
                }
            }
        }
    }

    private function address(DOMDocument $d, DOMElement $parent, string $name, Address $address): void
    {
        $node = $this->el($d, $parent, $name);
        $this->el($d, $node, 'KodKraju', $address->countryCode);
        $this->el($d, $node, 'AdresL1', $address->line1);
        if ($address->line2 !== null) {
            $this->el($d, $node, 'AdresL2', $address->line2);
        }
        if ($address->gln !== null) {
            $this->el($d, $node, 'GLN', $address->gln);
        }
    }

    private function contact(DOMDocument $d, DOMElement $parent, ?string $email, ?string $phone, string $emailName = 'Email', string $phoneName = 'Telefon'): void
    {
        if ($email === null && $phone === null) {
            return;
        }
        $node = $this->el($d, $parent, 'DaneKontaktowe');
        if ($email !== null) {
            $this->el($d, $node, $emailName, $email);
        }
        if ($phone !== null) {
            $this->el($d, $node, $phoneName, $phone);
        }
    }

    private function el(DOMDocument $document, DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $element = $document->createElementNS(self::NS, $name);
        if ($text !== null) {
            $element->appendChild($document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }
}
