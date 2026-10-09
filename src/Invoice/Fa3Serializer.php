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
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->el($d, $id, 'NIP', $seller->nip->value);
        $this->el($d, $id, 'Nazwa', $seller->name);
        $this->address($d, $node, 'Adres', $seller->address);
        $this->contact($d, $node, $seller->email, $seller->phone);
    }

    private function buyer(DOMDocument $d, DOMElement $root, Buyer $buyer): void
    {
        $node = $this->el($d, $root, 'Podmiot2');
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->identifier($d, $id, $buyer->identifier);
        $this->el($d, $id, 'Nazwa', $buyer->name);

        if ($buyer->address !== null) {
            $this->address($d, $node, 'Adres', $buyer->address);
        }
        $this->contact($d, $node, $buyer->email, $buyer->phone);
        if ($buyer->customerNumber !== null) {
            $this->el($d, $node, 'NrKlienta', $buyer->customerNumber);
        }
        $this->el($d, $node, 'JST', '2');
        $this->el($d, $node, 'GV', '2');
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

        if (!$invoice->type->isAdvance()) {
            foreach ($invoice->lines as $index => $line) {
                $this->line($d, $fa, $index + 1, $line);
            }
        }

        if ($invoice->payment !== null) {
            $this->payment($d, $fa, $invoice->payment);
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

        $this->el($d, $this->el($d, $node, 'NoweSrodkiTransportu'), 'P_22N', '1');
        $this->el($d, $node, 'P_23', '2');
        $this->el($d, $this->el($d, $node, 'PMarzy'), 'P_PMarzyN', '1');
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
    }

    private function line(DOMDocument $d, DOMElement $fa, int $number, InvoiceLine $line): void
    {
        $node = $this->el($d, $fa, 'FaWiersz');
        $this->el($d, $node, 'NrWierszaFa', (string) $number);
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
        $this->el($d, $node, 'P_11', $line->netAmount()->toString(2));
        $this->el($d, $node, 'P_12', $line->vatRate->value);
        if ($line->gtu !== null) {
            $this->el($d, $node, 'GTU', $line->gtu->value);
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

    private function payment(DOMDocument $d, DOMElement $fa, Payment $payment): void
    {
        $node = $this->el($d, $fa, 'Platnosc');
        if ($payment->paidOn !== null) {
            $this->el($d, $node, 'Zaplacono', '1');
            $this->el($d, $node, 'DataZaplaty', $payment->paidOn->format('Y-m-d'));
        }
        foreach ($payment->dueDates as $due) {
            $this->el($d, $this->el($d, $node, 'TerminPlatnosci'), 'Termin', $due->format('Y-m-d'));
        }
        if ($payment->method !== null) {
            $this->el($d, $node, 'FormaPlatnosci', (string) $payment->method->value);
        }
        foreach ($payment->bankAccounts as $account) {
            $this->el($d, $this->el($d, $node, 'RachunekBankowy'), 'NrRB', $account);
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
