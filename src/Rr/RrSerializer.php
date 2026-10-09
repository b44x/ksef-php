<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\LineState;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Serialises an {@see RrInvoice} to FA_RR (1) XML (schema version 1-1E).
 *
 * @internal
 */
final class RrSerializer
{
    private const NS = FormCode::RR_NAMESPACE;

    public function serialize(RrInvoice $invoice, DateTimeImmutable $createdAt): string
    {
        $d = new DOMDocument('1.0', 'UTF-8');
        $d->formatOutput = false;
        $root = $d->createElementNS(self::NS, 'Faktura');
        $d->appendChild($root);

        $header = $this->el($d, $root, 'Naglowek');
        $code = $this->el($d, $header, 'KodFormularza', 'FA_RR');
        $code->setAttribute('kodSystemowy', 'FA_RR (1)');
        $code->setAttribute('wersjaSchemy', '1-1E');
        $this->el($d, $header, 'WariantFormularza', '1');
        $this->el($d, $header, 'DataWytworzeniaFa', $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->el($d, $header, 'SystemInfo', 'ksef-php');

        $this->party($d, $root, 'Podmiot1', $invoice->supplier);
        $this->party($d, $root, 'Podmiot2', $invoice->buyer);
        $this->body($d, $root, $invoice);

        if ($invoice->footer !== null) {
            $footer = $this->el($d, $root, 'Stopka');
            $this->el($d, $this->el($d, $footer, 'Informacje'), 'StopkaFaktury', $invoice->footer);
        }

        $xml = $d->saveXML();

        return $xml === false ? '' : $xml;
    }

    private function body(DOMDocument $d, DOMElement $root, RrInvoice $invoice): void
    {
        $fa = $this->el($d, $root, 'FakturaRR');
        $this->el($d, $fa, 'KodWaluty', $invoice->currency);
        if ($invoice->issuePlace !== null) {
            $this->el($d, $fa, 'P_1M', $invoice->issuePlace);
        }
        if ($invoice->purchaseDate !== null) {
            $this->el($d, $fa, 'P_4A', $invoice->purchaseDate->format('Y-m-d'));
        }
        $this->el($d, $fa, 'P_4B', $invoice->issueDate->format('Y-m-d'));
        $this->el($d, $fa, 'P_4C', $invoice->number);
        $this->el($d, $fa, 'P_11_1', $invoice->value()->toString(2));
        $this->el($d, $fa, 'P_11_2', $invoice->refund()->toString(2));
        $this->el($d, $fa, 'P_12_1', $invoice->total()->toString(2));
        $this->el($d, $fa, 'P_12_2', $invoice->totalInWords());
        $this->el($d, $fa, 'RodzajFaktury', $invoice->isCorrection() ? 'KOR_VAT_RR' : 'VAT_RR');

        if ($invoice->correction !== null) {
            if ($invoice->correction->reason !== null) {
                $this->el($d, $fa, 'PrzyczynaKorekty', $invoice->correction->reason);
            }
            $this->el($d, $fa, 'TypKorekty', (string) $invoice->correction->type->value);
            foreach ($invoice->correction->correctedInvoices as $corrected) {
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

        foreach ($invoice->lines as $index => $line) {
            $this->line($d, $fa, $index + 1, $line);
        }

        if ($invoice->payment !== null) {
            $this->payment($d, $fa, $invoice->payment);
        }
    }

    private function line(DOMDocument $d, DOMElement $fa, int $number, RrLine $line): void
    {
        $node = $this->el($d, $fa, 'FakturaRRWiersz');
        $this->el($d, $node, 'NrWierszaFa', (string) $number);
        if ($line->purchaseDate !== null) {
            $this->el($d, $node, 'P_4AA', $line->purchaseDate->format('Y-m-d'));
        }
        $this->el($d, $node, 'P_5', $line->name);
        if ($line->gtin !== null) {
            $this->el($d, $node, 'GTIN', $line->gtin);
        }
        if ($line->pkwiu !== null) {
            $this->el($d, $node, 'PKWiU', $line->pkwiu);
        }
        if ($line->cn !== null) {
            $this->el($d, $node, 'CN', $line->cn);
        }
        $this->el($d, $node, 'P_6A', $line->unit);
        $this->el($d, $node, 'P_6B', $line->quantity->toTrimmedString());
        $this->el($d, $node, 'P_6C', $line->quality);
        $this->el($d, $node, 'P_7', $line->unitPrice->toTrimmedString(2));
        $this->el($d, $node, 'P_8', $line->value()->toString(2));
        $this->el($d, $node, 'P_9', $line->rate->value);
        $this->el($d, $node, 'P_10', $line->refund()->toString(2));
        $this->el($d, $node, 'P_11', $line->total()->toString(2));
        if ($line->state === LineState::Before) {
            $this->el($d, $node, 'StanPrzed', '1');
        }
    }

    private function payment(DOMDocument $d, DOMElement $fa, RrPayment $payment): void
    {
        $node = $this->el($d, $fa, 'Platnosc');
        if ($payment->transfer) {
            $this->el($d, $node, 'FormaPlatnosci', '1');
        } else {
            $this->el($d, $node, 'PlatnoscInna', '1');
            $this->el($d, $node, 'OpisPlatnosci', (string) $payment->otherDescription);
        }
        if ($payment->farmerAccount !== null) {
            $this->el($d, $this->el($d, $node, 'RachunekBankowy1'), 'NrRB', $payment->farmerAccount);
        }
        if ($payment->buyerAccount !== null) {
            $this->el($d, $this->el($d, $node, 'RachunekBankowy2'), 'NrRB', $payment->buyerAccount);
        }
    }

    private function party(DOMDocument $d, DOMElement $root, string $name, RrParty $party): void
    {
        $node = $this->el($d, $root, $name);
        $id = $this->el($d, $node, 'DaneIdentyfikacyjne');
        $this->el($d, $id, 'NIP', $party->nip->value);
        $this->el($d, $id, 'Nazwa', $party->name);
        $this->address($d, $node, $party->address);
        if ($party->email !== null || $party->phone !== null) {
            $contact = $this->el($d, $node, 'DaneKontaktowe');
            if ($party->email !== null) {
                $this->el($d, $contact, 'Email', $party->email);
            }
            if ($party->phone !== null) {
                $this->el($d, $contact, 'Telefon', $party->phone);
            }
        }
    }

    private function address(DOMDocument $d, DOMElement $parent, Address $address): void
    {
        $node = $this->el($d, $parent, 'Adres');
        $this->el($d, $node, 'KodKraju', $address->countryCode);
        $this->el($d, $node, 'AdresL1', $address->line1);
        if ($address->line2 !== null) {
            $this->el($d, $node, 'AdresL2', $address->line2);
        }
        if ($address->gln !== null) {
            $this->el($d, $node, 'GLN', $address->gln);
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
