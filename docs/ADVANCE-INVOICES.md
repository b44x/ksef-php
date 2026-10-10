# Advance, settlement and their corrections: how the fields are filled

This page records how the SDK fills FA(3) for invoices with advance payments (art. 106f of the VAT Act) and why. The
rules come from the documentation embedded in the official schema (`schemat_FA3_v1-0E.xsd`), and the examples were
accepted by KSeF TEST. KSeF TEST checks the structure, not the bookkeeping, so **have an accountant confirm the
corrections for your own cases** (the open points are listed at the end).

| Field | `ZAL` advance | `ROZ` final | `KOR_ZAL` | `KOR_ROZ` |
|-------|---------------|-------------|-----------|-----------|
| `P_13_x` net | net part of the advance | net of the whole sale | **difference** of the advance net | **difference** of the net |
| `P_14_x` VAT | `gross * rate / (100 + rate)` (art. 106f(1)(3)) | VAT of the whole sale | difference of that VAT | difference of the VAT |
| `P_15` | the payment documented | amount left to pay | correction of the amount of the corrected invoice | correction of the amount left to pay |
| `P_15ZK` | - | - | payment **before** the correction | amount left to pay **before** the correction |
| `P_6` | date the payment was received | date of delivery | date the payment was received | date of delivery |
| Lines | none; the order goes in `Zamowienie` | `FaWiersz` of the whole sale | none; order rows before and after in `Zamowienie` | `FaWiersz` before and after |
| `FakturaZaliczkowa` | - | the advance invoices (KSeF number, or number if issued outside KSeF) | - | the advance invoices |

How that maps to the SDK:

- `AdvancePayment::$paid` is the gross payment; on `KOR_ZAL` it is the **change** of the payment, negative when it
  decreases. Tax is taken out of the gross, per rate, rounded half away from zero.
- `Settlement::$advancesPaid` is the gross already paid in advances; on `KOR_ROZ` it is the change of that amount.
  `P_15` = invoice total (a difference on corrections) minus that amount.
- `Correction::$amountBefore` fills `P_15ZK`. It is optional for the schema but is the "before" figure the schema
  describes for both corrections, so provide it.
- On corrections, lines marked `asBefore()` are subtracted: the totals become differences automatically.

## Open points

1. `WartoscZamowienia` on a `KOR_ZAL`: the schema says only "order value including tax". The SDK writes the value of
   the order **after** the correction (the lines that are not marked `asBefore()`). If your accountant wants the
   difference there, send the invoice as raw XML.
2. A `ROZ` that also documents a part payment received before delivery (`ZaliczkaCzesciowa` with `P_6Z` / `P_15Z`) is
   not modelled.
3. Foreign-currency corrections: `Correction::$exchangeRateBefore` (`KursWalutyZK`) is written, but only PLN
   scenarios were exercised.
