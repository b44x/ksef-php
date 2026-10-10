<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\LineState;
use B4x\Ksef\Support\Decimal;
use B4x\Ksef\Support\KsefNumber;
use DateTimeImmutable;

/**
 * Checks the invariants of an RR invoice that do not need KSeF (schema restrictions and the obvious semantic rules).
 *
 * @internal
 */
final class RrValidator
{
    /** @var list<string> */
    private array $violations = [];

    private function __construct(private readonly RrInvoice $invoice) {}

    /**
     * @return list<string>
     */
    public static function violations(RrInvoice $invoice): array
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
        if ($i->purchaseDate !== null) {
            $this->date('Purchase date', $i->purchaseDate);
        }
        if ($i->issuePlace !== null) {
            $this->text('Issue place', $i->issuePlace, 256);
        }
        if ($i->footer !== null) {
            $this->text('Footer', $i->footer, 3500);
        }
        if ($i->currency !== 'PLN') {
            $this->add('RR invoices are supported in PLN only.');
        }
        $this->party('Supplier', $i->supplier);
        $this->party('Buyer', $i->buyer);
        if ($i->supplier->nip->value === $i->buyer->nip->value) {
            $this->add('The supplier and the buyer of an RR invoice must be different entities.');
        }

        $this->lines();
        $this->correction();

        if ($i->payment !== null) {
            foreach (['farmer account' => $i->payment->farmerAccount, 'buyer account' => $i->payment->buyerAccount] as $label => $account) {
                if ($account !== null && (\strlen($account) < 10 || \strlen($account) > 34)) {
                    $this->add(\sprintf('The %s must have 10 to 34 characters.', $label));
                }
            }
            if ($i->payment->otherDescription !== null) {
                $this->text('Payment description', $i->payment->otherDescription, 256);
            }
        }
        if ($i->amountInWords !== null) {
            $this->text('Amount in words', $i->amountInWords, 256);
        }
        if ($i->total()->abs()->compare(Decimal::of('9999999999999999.99')) > 0) {
            $this->add('The invoice total exceeds the maximum amount supported by the schema.');
        }
    }

    private function lines(): void
    {
        $i = $this->invoice;
        if ($i->lines === []) {
            $this->add('An RR invoice needs at least one line.');

            return;
        }
        if (\count($i->lines) > 10_000) {
            $this->add('An RR invoice can have at most 10,000 lines.');
        }

        $before = 0;
        $dateless = 0;
        foreach ($i->lines as $index => $line) {
            $label = \sprintf('Line %d', $index + 1);
            $this->text($label . ' name', $line->name, 512);
            $this->text($label . ' unit', $line->unit, 256);
            $this->text($label . ' quality', $line->quality, 256);
            if (!$line->quantity->isPositive() && !($i->isCorrection() && $line->quantity->isZero() && $line->state === LineState::Current)) {
                $this->add($label . ': the quantity must be positive.');
            }
            if ($line->quantity->scale() > 6 && !$line->quantity->equals($line->quantity->roundTo(6))) {
                $this->add($label . ': the quantity allows at most 6 decimal places.');
            }
            if ($line->unitPrice->isNegative()) {
                $this->add($label . ': the unit price must not be negative.');
            }
            if ($line->unitPrice->scale() > 8 && !$line->unitPrice->equals($line->unitPrice->roundTo(8))) {
                $this->add($label . ': the unit price allows at most 8 decimal places.');
            }
            foreach (['PKWiU' => $line->pkwiu, 'CN' => $line->cn] as $field => $value) {
                if ($value !== null) {
                    $this->text($label . ' ' . $field, $value, 50);
                }
            }
            if ($line->gtin !== null) {
                $this->text($label . ' GTIN', $line->gtin, 20);
            }
            if ($line->purchaseDate !== null) {
                $this->date($label . ' purchase date', $line->purchaseDate);
            } else {
                ++$dateless;
            }
            if ($line->state === LineState::Before) {
                ++$before;
            }
        }

        if ($dateless > 0 && $i->purchaseDate === null) {
            $this->add('Give the purchase date: either for the whole invoice or for every line.');
        }
        if (!$i->isCorrection() && $before > 0) {
            $this->add('"Before correction" lines are only allowed on correction invoices.');
        }
        if ($i->isCorrection() && $before === 0) {
            $this->add('A correction invoice needs at least one "before correction" line (RrLine::asBefore()).');
        }
    }

    private function correction(): void
    {
        $correction = $this->invoice->correction;
        if ($correction === null) {
            return;
        }
        if ($correction->reason !== null) {
            $this->text('Correction reason', $correction->reason, 256);
        }
        foreach ($correction->correctedInvoices as $index => $corrected) {
            $this->text(\sprintf('Corrected invoice %d number', $index + 1), $corrected->number, 256);
            $this->date(\sprintf('Corrected invoice %d date', $index + 1), $corrected->issueDate);
            if ($corrected->ksefNumber !== null) {
                $error = KsefNumber::validate($corrected->ksefNumber);
                if ($error !== null) {
                    $this->add(\sprintf('"%s" is not a valid KSeF number (%s).', $corrected->ksefNumber, $error));
                }
            }
        }
    }

    private function party(string $label, RrParty $party): void
    {
        $this->text($label . ' name', $party->name, 512);
        $this->address($label, $party->address);
        if ($party->email !== null && (\strlen($party->email) < 3 || \strlen($party->email) > 255 || preg_match('/^.+@.+$/', $party->email) !== 1)) {
            $this->add(\sprintf('%s e-mail "%s" is not valid.', $label, $party->email));
        }
        if ($party->phone !== null) {
            $this->text($label . ' phone', $party->phone, 16);
        }
    }

    private function address(string $label, Address $address): void
    {
        if (preg_match('/^[A-Z]{2}$/', $address->countryCode) !== 1) {
            $this->add(\sprintf('%s address: the country code must be a two-letter upper case code.', $label));
        }
        $this->text($label . ' address line 1', $address->line1, 512);
        if ($address->line2 !== null) {
            $this->text($label . ' address line 2', $address->line2, 512);
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
        if (preg_match('/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{84}\x{86}-\x{9F}]/u', $value) === 1) {
            $this->add(\sprintf('%s contains characters that KSeF rejects (control characters).', $label));
        }
    }

    private function date(string $label, DateTimeImmutable $date): void
    {
        $day = $date->format('Y-m-d');
        if ($day < '2006-01-01' || $day > '2050-01-01') {
            $this->add(\sprintf('%s %s must be between 2006-01-01 and 2050-01-01.', $label, $day));
        }
    }

    private function add(string $violation): void
    {
        $this->violations[] = $violation;
    }
}
