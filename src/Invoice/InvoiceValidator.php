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
        if ($count < 1 || $count > self::MAX_LINES) {
            $this->add(\sprintf('An invoice needs between 1 and %d lines, %d given.', self::MAX_LINES, $count));
        }

        $before = 0;
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
            if ($line->quantity->isNegative() || ($line->state === LineState::Current && $i->type !== InvoiceType::Correction && $line->quantity->isZero())) {
                $this->add($label . ': the quantity must be positive.');
            }
            if ($line->quantity->scale() > 6 && !$line->quantity->equals($line->quantity->roundTo(6))) {
                $this->add($label . ': the quantity allows at most 6 decimal places.');
            }
            if ($line->unitNetPrice->isNegative()) {
                $this->add($label . ': the unit price must not be negative.');
            }
            if ($line->unitNetPrice->amount->scale() > 8 && !$line->unitNetPrice->amount->equals($line->unitNetPrice->amount->roundTo(8))) {
                $this->add($label . ': the unit price allows at most 8 decimal places.');
            }
            if ($line->unitNetPrice->currency !== $i->currency) {
                $this->add(\sprintf('%s: the price currency %s differs from the invoice currency %s.', $label, $line->unitNetPrice->currency, $i->currency));
            }
            if ($line->state === LineState::Before) {
                ++$before;
            }
        }

        if ($i->type !== InvoiceType::Correction && $before > 0) {
            $this->add('"Before correction" lines are only allowed on correction invoices.');
        }
        if ($i->type === InvoiceType::Correction && $before === 0) {
            $this->add('A correction invoice needs at least one "before correction" line (InvoiceLine::asBefore()).');
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
        if ($i->type === InvoiceType::Correction) {
            if ($i->correction === null || $i->correction->correctedInvoices === []) {
                $this->add('A correction invoice needs the corrected invoice reference(s).');

                return;
            }
            if ($i->correction->reason !== null) {
                $this->text('Correction reason', $i->correction->reason, 256);
            }
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

    private function kindSpecificRules(): void
    {
        $i = $this->invoice;

        if ($i->type !== InvoiceType::Advance && $i->advance !== null) {
            $this->add('Advance payment data is only allowed on advance invoices (ZAL).');
        }
        if ($i->type !== InvoiceType::Settlement && $i->settlement !== null) {
            $this->add('Settlement data is only allowed on settlement invoices (ROZ).');
        }

        switch ($i->type) {
            case InvoiceType::Advance:
                if ($i->advance === null) {
                    $this->add('An advance invoice needs the advance payment (AdvancePayment).');

                    break;
                }
                if ($i->advance->paid->currency !== $i->currency) {
                    $this->add('The advance payment currency differs from the invoice currency.');
                }
                if (!$i->advance->paid->amount->isPositive()) {
                    $this->add('The advance payment must be positive.');
                }
                $this->date('Advance payment date', $i->advance->receivedOn);
                if ($i->saleDate !== null) {
                    $this->add('An advance invoice takes its date from the payment; do not set a sale date.');
                }

                break;
            case InvoiceType::Settlement:
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
                if ($paid->isNegative() || $paid->amount->roundTo(2)->compare($i->totals()->gross()) > 0) {
                    $this->add('The advances paid must be between zero and the invoice total.');
                }

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

    private function amounts(): void
    {
        $limit = Decimal::of('9999999999999999.99');
        $totals = $this->invoice->totals();
        if ($totals->gross()->abs()->compare($limit) > 0) {
            $this->add('The invoice total exceeds the maximum amount supported by the schema.');
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
