<?php

declare(strict_types=1);

namespace B4x\Ksef\Reader;

use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\FormCode;
use B4x\Ksef\Invoice\InvoiceDocument;
use B4x\Ksef\Invoice\InvoiceType;
use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Xml\SafeXml;
use DateTimeImmutable;
use DOMElement;
use DOMXPath;

/**
 * Reads an FA(3) invoice (for example one downloaded from KSeF) into a typed {@see ReceivedInvoice}.
 *
 * The document is checked against the bundled schema first, so what is read is a valid FA(3) invoice. Amounts are taken
 * as written; nothing is recalculated. FA_RR and Peppol documents are not read by this class.
 */
final class InvoiceReader
{
    /** @var list<string> the groups of the tax summary, in schema order */
    private const GROUPS = ['1', '2', '3', '4', '5', '6_1', '6_2', '6_3', '7', '8', '9', '10', '11'];

    /**
     * @param InvoiceDocument|string $invoice a verified document, or the XML (which is then verified)
     *
     * @throws ValidationException|\B4x\Ksef\Exception\SerializationException when the XML is not a valid FA(3) invoice
     */
    public function read(InvoiceDocument|string $invoice): ReceivedInvoice
    {
        $document = $invoice instanceof InvoiceDocument ? $invoice : InvoiceDocument::fromXml($invoice);
        if (!$document->formCode->equals(FormCode::fa3())) {
            throw new ValidationException(\sprintf('Only FA(3) invoices can be read, this is %s.', $document->formCode->systemCode), ['Not an FA(3) invoice.']);
        }

        $dom = SafeXml::load($document->xml);
        $x = new DOMXPath($dom);
        $x->registerNamespace('f', FormCode::FA3_NAMESPACE);

        $fa = $this->node($x, '/f:Faktura/f:Fa');
        if ($fa === null) {
            throw new ValidationException('The invoice has no Fa element.');
        }

        $type = InvoiceType::tryFrom($this->text($x, 'f:RodzajFaktury', $fa) ?? '')
            ?? throw new ValidationException('The invoice has an unknown kind (RodzajFaktury).');
        $seller = $this->node($x, '/f:Faktura/f:Podmiot1');
        $buyer = $this->node($x, '/f:Faktura/f:Podmiot2');
        if ($seller === null || $buyer === null) {
            throw new ValidationException('The invoice has no seller or buyer.');
        }

        $authorized = $this->node($x, '/f:Faktura/f:PodmiotUpowazniony');

        return new ReceivedInvoice(
            $document,
            $this->required($x, 'f:P_2', $fa),
            $this->date($this->required($x, 'f:P_1', $fa)),
            $this->required($x, 'f:KodWaluty', $fa),
            $type,
            $this->party($x, $seller),
            $this->party($x, $buyer),
            Decimal::of($this->required($x, 'f:P_15', $fa)),
            $this->thirdParties($x),
            $this->optionalDate($x, 'f:P_6', $fa),
            $this->optionalDate($x, 'f:OkresFa/f:P_6_Od', $fa),
            $this->optionalDate($x, 'f:OkresFa/f:P_6_Do', $fa),
            $this->text($x, 'f:P_1M', $fa),
            $this->lines($x, $fa),
            $this->taxSummary($x, $fa),
            $this->optionalDecimal($x, 'f:KursWalutyZ', $fa),
            $this->payment($x, $fa),
            $type->isCorrection() ? $this->correction($x, $fa) : null,
            $this->advanceInvoices($x, $fa),
            $this->notes($x, $fa),
            $this->texts($x, 'f:WZ', $fa),
            $this->text($x, '/f:Faktura/f:Stopka/f:Informacje/f:StopkaFaktury'),
            $this->node($x, '/f:Faktura/f:Zalacznik') !== null,
            $this->annotations($x, $fa),
            $this->optionalDecimal($x, 'f:Zamowienie/f:WartoscZamowienia', $fa),
            $authorized === null ? null : $this->party($x, $authorized),
        );
    }

    private function party(DOMXPath $x, DOMElement $p): ReceivedParty
    {
        $id = $this->node($x, 'f:DaneIdentyfikacyjne', $p);
        $euCountry = $this->text($x, 'f:DaneIdentyfikacyjne/f:KodUE', $p);
        $euNumber = $this->text($x, 'f:DaneIdentyfikacyjne/f:NrVatUE', $p);
        $foreignId = $this->text($x, 'f:DaneIdentyfikacyjne/f:NrID', $p);

        return new ReceivedParty(
            $id === null ? '' : ($this->text($x, 'f:Nazwa', $id) ?? ''),
            $this->text($x, 'f:DaneIdentyfikacyjne/f:NIP', $p),
            $euCountry,
            $euNumber,
            $foreignId === null ? null : $this->text($x, 'f:DaneIdentyfikacyjne/f:KodKraju', $p),
            $foreignId,
            $this->text($x, 'f:DaneIdentyfikacyjne/f:BrakID', $p) === '1',
            $this->text($x, 'f:NrEORI', $p),
            $this->address($x, 'f:Adres', $p),
            $this->address($x, 'f:AdresKoresp', $p),
            $this->text($x, 'f:DaneKontaktowe/f:Email', $p) ?? $this->text($x, 'f:DaneKontaktowe/f:EmailPU', $p),
            $this->text($x, 'f:DaneKontaktowe/f:Telefon', $p) ?? $this->text($x, 'f:DaneKontaktowe/f:TelefonPU', $p),
            $this->text($x, 'f:Rola', $p) ?? $this->text($x, 'f:RolaPU', $p),
            $this->text($x, 'f:OpisRoli', $p),
            $this->text($x, 'f:NrKlienta', $p),
            $this->text($x, 'f:PrefiksPodatnika', $p),
        );
    }

    /** @return list<ReceivedParty> */
    private function thirdParties(DOMXPath $x): array
    {
        $parties = [];
        foreach ($this->nodes($x, '/f:Faktura/f:Podmiot3') as $node) {
            $parties[] = $this->party($x, $node);
        }

        return $parties;
    }

    private function address(DOMXPath $x, string $path, DOMElement $parent): ?Address
    {
        $node = $this->node($x, $path, $parent);
        if ($node === null) {
            return null;
        }

        return new Address(
            $this->text($x, 'f:KodKraju', $node) ?? '',
            $this->text($x, 'f:AdresL1', $node) ?? '',
            $this->text($x, 'f:AdresL2', $node),
            $this->text($x, 'f:GLN', $node),
        );
    }

    /** @return list<ReceivedLine> */
    private function lines(DOMXPath $x, DOMElement $fa): array
    {
        $lines = [];
        foreach ($this->nodes($x, 'f:FaWiersz', $fa) as $index => $row) {
            $lines[] = new ReceivedLine(
                (int) ($this->text($x, 'f:NrWierszaFa', $row) ?? $index + 1),
                $this->text($x, 'f:P_7', $row) ?? '',
                $this->text($x, 'f:P_8A', $row),
                $this->optionalDecimal($x, 'f:P_8B', $row),
                $this->optionalDecimal($x, 'f:P_9A', $row),
                $this->optionalDecimal($x, 'f:P_9B', $row),
                $this->optionalDecimal($x, 'f:P_10', $row),
                $this->optionalDecimal($x, 'f:P_11', $row),
                $this->optionalDecimal($x, 'f:P_11A', $row),
                $this->optionalDecimal($x, 'f:P_11Vat', $row),
                $this->text($x, 'f:P_12', $row) ?? '',
                $this->text($x, 'f:GTU', $row),
                $this->text($x, 'f:Procedura', $row),
                $this->text($x, 'f:Indeks', $row),
                $this->text($x, 'f:GTIN', $row),
                $this->text($x, 'f:PKWiU', $row),
                $this->text($x, 'f:CN', $row),
                $this->optionalDate($x, 'f:P_6A', $row),
                $this->text($x, 'f:StanPrzed', $row) === '1',
            );
        }

        return $lines;
    }

    /** @return list<TaxSummaryRow> */
    private function taxSummary(DOMXPath $x, DOMElement $fa): array
    {
        $rows = [];
        foreach (self::GROUPS as $group) {
            $net = $this->optionalDecimal($x, 'f:P_13_' . $group, $fa);
            if ($net === null) {
                continue;
            }
            $rows[] = new TaxSummaryRow(
                $group,
                $net,
                $this->optionalDecimal($x, 'f:P_14_' . $group, $fa),
                $this->optionalDecimal($x, 'f:P_14_' . $group . 'W', $fa),
            );
        }

        return $rows;
    }

    private function payment(DOMXPath $x, DOMElement $fa): ?ReceivedPayment
    {
        $p = $this->node($x, 'f:Platnosc', $fa);
        if ($p === null) {
            return null;
        }

        $partial = [];
        foreach ($this->nodes($x, 'f:ZaplataCzesciowa', $p) as $row) {
            $method = $this->text($x, 'f:FormaPlatnosci', $row);
            $partial[] = new ReceivedPartialPayment(
                Decimal::of($this->required($x, 'f:KwotaZaplatyCzesciowej', $row)),
                $this->date($this->required($x, 'f:DataZaplatyCzesciowej', $row)),
                $method === null ? null : (int) $method,
            );
        }
        $due = [];
        foreach ($this->texts($x, 'f:TerminPlatnosci/f:Termin', $p) as $day) {
            $due[] = $this->date($day);
        }
        $flag = $this->text($x, 'f:ZnacznikZaplatyCzesciowej', $p);
        $method = $this->text($x, 'f:FormaPlatnosci', $p);

        return new ReceivedPayment(
            $this->text($x, 'f:Zaplacono', $p) === '1' ? $this->optionalDate($x, 'f:DataZaplaty', $p) : null,
            $due,
            $method === null ? null : (int) $method,
            $this->text($x, 'f:OpisPlatnosci', $p),
            $this->texts($x, 'f:RachunekBankowy/f:NrRB', $p),
            $partial,
            $flag === null ? null : $flag === '2',
            $this->text($x, 'f:Skonto/f:WarunkiSkonta', $p),
            $this->text($x, 'f:Skonto/f:WysokoscSkonta', $p),
        );
    }

    private function correction(DOMXPath $x, DOMElement $fa): ReceivedCorrection
    {
        $corrected = [];
        foreach ($this->nodes($x, 'f:DaneFaKorygowanej', $fa) as $row) {
            $corrected[] = new ReceivedCorrectedInvoice(
                $this->optionalDate($x, 'f:DataWystFaKorygowanej', $row),
                $this->text($x, 'f:NrFaKorygowanej', $row) ?? '',
                $this->text($x, 'f:NrKSeFFaKorygowanej', $row),
            );
        }
        $type = $this->text($x, 'f:TypKorekty', $fa);

        return new ReceivedCorrection(
            $corrected,
            $this->text($x, 'f:PrzyczynaKorekty', $fa),
            $type === null ? null : (int) $type,
            $this->text($x, 'f:OkresFaKorygowanej', $fa),
            $this->optionalDecimal($x, 'f:P_15ZK', $fa),
        );
    }

    /** @return list<string> */
    private function advanceInvoices(DOMXPath $x, DOMElement $fa): array
    {
        $numbers = [];
        foreach ($this->nodes($x, 'f:FakturaZaliczkowa', $fa) as $row) {
            $number = $this->text($x, 'f:NrKSeFFaZaliczkowej', $row) ?? $this->text($x, 'f:NrFaZaliczkowej', $row);
            if ($number !== null) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /** @return list<ReceivedNote> */
    private function notes(DOMXPath $x, DOMElement $fa): array
    {
        $notes = [];
        foreach ($this->nodes($x, 'f:DodatkowyOpis', $fa) as $row) {
            $line = $this->text($x, 'f:NrWiersza', $row);
            $notes[] = new ReceivedNote($this->text($x, 'f:Klucz', $row) ?? '', $this->text($x, 'f:Wartosc', $row) ?? '', $line === null ? null : (int) $line);
        }

        return $notes;
    }

    private function annotations(DOMXPath $x, DOMElement $fa): ReceivedAnnotations
    {
        $a = $this->node($x, 'f:Adnotacje', $fa);
        if ($a === null) {
            return new ReceivedAnnotations();
        }
        $exemption = $this->text($x, 'f:Zwolnienie/f:P_19', $a) === '1'
            ? ($this->text($x, 'f:Zwolnienie/f:P_19A', $a) ?? $this->text($x, 'f:Zwolnienie/f:P_19B', $a) ?? $this->text($x, 'f:Zwolnienie/f:P_19C', $a))
            : null;

        return new ReceivedAnnotations(
            $this->text($x, 'f:P_16', $a) === '1',
            $this->text($x, 'f:P_17', $a) === '1',
            $this->text($x, 'f:P_18', $a) === '1',
            $this->text($x, 'f:P_18A', $a) === '1',
            $exemption,
            $this->text($x, 'f:P_23', $a) === '1',
            $this->text($x, 'f:PMarzy/f:P_PMarzy', $a) === '1',
            $this->text($x, 'f:NoweSrodkiTransportu/f:P_22', $a) === '1',
            $this->text($x, 'f:FP', $fa) === '1',
            $this->text($x, 'f:TP', $fa) === '1',
            $this->text($x, 'f:ZwrotAkcyzy', $fa) === '1',
        );
    }

    private function node(DOMXPath $x, string $path, ?DOMElement $context = null): ?DOMElement
    {
        $nodes = $x->query($path, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /** @return list<DOMElement> */
    private function nodes(DOMXPath $x, string $path, ?DOMElement $context = null): array
    {
        $found = [];
        $nodes = $x->query($path, $context);
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function text(DOMXPath $x, string $path, ?DOMElement $context = null): ?string
    {
        $node = $this->node($x, $path, $context);

        return $node === null ? null : trim($node->textContent);
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $path, DOMElement $context): array
    {
        return array_map(static fn(DOMElement $node): string => trim($node->textContent), $this->nodes($x, $path, $context));
    }

    private function required(DOMXPath $x, string $path, DOMElement $context): string
    {
        return $this->text($x, $path, $context) ?? throw new ValidationException(\sprintf('The invoice has no %s.', $path));
    }

    private function optionalDecimal(DOMXPath $x, string $path, DOMElement $context): ?Decimal
    {
        $text = $this->text($x, $path, $context);

        return $text === null || $text === '' ? null : Decimal::of($text);
    }

    private function optionalDate(DOMXPath $x, string $path, DOMElement $context): ?DateTimeImmutable
    {
        $text = $this->text($x, $path, $context);

        return $text === null || $text === '' ? null : $this->date($text);
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException(\sprintf('"%s" is not a valid invoice date.', $value));
        }

        return $date;
    }
}
