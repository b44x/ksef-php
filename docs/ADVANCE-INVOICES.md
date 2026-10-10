# Advance, settlement and their corrections: how the fields are filled

This page records how the SDK fills FA(3) for invoices with advance payments (art. 106f of the VAT Act) and why. The
rules come from the documentation embedded in the official schema (`schemat_FA3_v1-0E.xsd`) and from the Ministry of
Finance's KSeF 2.0 handbook, part II, sections 2.6-2.8 and 2.13.5 ("Podręcznik KSeF 2.0, cz. II", published on
ksef.podatki.gov.pl); the examples were accepted by KSeF TEST. TEST checks the structure, not the bookkeeping, so **have an
accountant confirm the corrections for your own cases** (the open points are listed at the end).

| Field | `ZAL` advance | `ROZ` final | `KOR_ZAL` | `KOR_ROZ` |
|-------|---------------|-------------|-----------|-----------|
| `P_13_x` net | net part of the advance | net of what **remains to pay** (sale minus the net of the advances) | **difference** of the advance net | **difference** of that net |
| `P_14_x` VAT | `gross * rate / (100 + rate)` (art. 106f(1)(3)) | VAT of the sale minus the VAT shown on the advance invoices | difference of that VAT | difference of the VAT |
| `P_15` | the payment documented | amount left to pay | correction of the amount of the corrected invoice | correction of the amount left to pay |
| `P_15ZK` | - | - | payment **before** the correction | amount left to pay **before** the correction |
| `P_6` | date the payment was received | date of delivery | date the payment was received | date of delivery |
| Lines | none; the order goes in `Zamowienie` | `FaWiersz` of the whole sale | none; order rows before and after in `Zamowienie` | `FaWiersz` before and after |
| `FakturaZaliczkowa` | - | the advance invoices (KSeF number, or number if issued outside KSeF) | - | the advance invoices |

How that maps to the SDK:

- `AdvancePayment::$paid` is the gross payment; on `KOR_ZAL` it is the **change** of the payment, negative when it
  decreases. Tax is taken out of the gross, per rate, rounded half away from zero.
- `Settlement::$advancesPaid` is the gross already paid in advances; on `KOR_ROZ` it is the change of that amount.
  The header shows what remains: per rate, the tax inside the advances (`gross * rate / (100 + rate)`) is deducted from
  the sale's tax and the rest from its net value, so `P_15` (= net + tax) is the amount still due. The lines keep the
  full values. The rate of the advances is inferred when the invoice has one taxed rate; otherwise give
  `Settlement::$advanceRate`, or `Settlement::$advanceAmounts` when the advances had several rates.
- `Correction::$amountBefore` fills `P_15ZK`. It is optional for the schema but is the "before" figure the schema
  describes for both corrections, so provide it.
- On corrections, lines marked `asBefore()` are subtracted: the totals become differences automatically.

## Open points

Confirmed by the handbook (2.13.5): `WartoscZamowienia` on a `KOR_ZAL` is the value of the order **after** the
correction, the sum of the gross values of the order rows in the corrected state; corrections of advance and settlement
invoices should show the rows before and after the correction even when a row does not change; `P_15` is the difference
against the original. Settlement invoices show only the remaining amount in `P_13` / `P_14` / `P_15` (2.7).

1. Collective corrections for a period (art. 106j(3), `OkresFaKorygowanej`, no lines) are not modelled; send them as
   raw XML.
2. A `ROZ` that also documents a part payment received before delivery (`ZaliczkaCzesciowa` with `P_6Z` / `P_15Z`) is
   not modelled.
3. Foreign-currency corrections: `Correction::$exchangeRateBefore` (`KursWalutyZK`) is written, but only PLN
   scenarios were exercised.
