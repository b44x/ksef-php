# Offline invoicing

When KSeF cannot be used (or you choose not to depend on it), an invoice is issued **offline** and delivered to
KSeF later. The SDK covers the technical side; the legal modes and deadlines are the Ministry's rules.

| Mode | Who triggers it | Deliver to KSeF |
|------|-----------------|-----------------|
| `offline24` | you, at will | by the next business day after the issue date |
| `offline` | KSeF unavailable (announced) | by the next business day after the unavailability ends |
| emergency | KSeF failure (announced) | within 7 business days after the failure ends |
| total failure | announced in the media | no KSeF delivery and no QR codes; the SDK has nothing to do |

Always check the current rules and announcements; deadlines move when a failure is announced.

## What you need

A KSeF certificate of type **Offline** for the seller. It can only confirm the issuer for KOD II; it cannot log in.
Request it while KSeF is reachable and keep it ready (the key is generated locally):

```php
$issued = $ksef->requestCertificate('offline invoicing', CertificateType::Offline);
$certificate = $issued->toOfflineCertificate();   // persist $issued->certificatePem and the private key securely
```

## Issue (works without any network access)

```php
$offline = $ksef->issueOfflineInvoice($invoice, $certificate);

$offline->document;          // InvoiceDocument: the XML you must keep and deliver unchanged
$offline->verificationUrl;   // KOD I: encode as QR, caption "OFFLINE" (or the KSeF number once known)
$offline->issuerUrl;         // KOD II: encode as QR, confirms the issuer
```

Print both codes on the visualisation. The hash in the links covers the exact XML bytes, so store the document
you issued and send those bytes, not a re-rendered copy.

`issueOfflineInvoice()` needs a client built with `->environment(...)` (the links differ per environment). Without
a network it also needs no authentication: build the issuer directly if the client cannot be created:

```php
$issuer = new OfflineIssuer(Environment::Production, ContextIdentifier::nip('5265877635'), $certificate);
$offline = $issuer->issue($invoice);
```

## Deliver later

```php
$submission = $ksef->sendOfflineInvoice($offline);                    // sends with offlineMode: true
$ksef->waitForInvoice($submission, untilStored: true)->assertAccepted();
```

For many invoices use `sendBatch($documents, BatchOptions::offline())` or `SendOptions::offline()` on an open session.

A corrective invoice (KOR) is sent only after the original has a KSeF number.

If you send an invoice as online but it is older than the day it reaches KSeF, KSeF marks it offline on its own
(it compares `P_1` with the acceptance day). Nothing to do for that.

## When KSeF rejects an offline invoice for a technical reason

Examples: schema violation, size, other technical validation. Re-issue the invoice with the same business content
but valid, and link it to the rejected one:

```php
$ksef->sendTechnicalCorrection($fixedInvoice, $rejectedOfflineInvoice);   // or the rejected hash
```

Rules (KSeF): interactive session only (the SDK uses one), the content must not change, the rejected invoice must
really have been rejected technically (not for missing permissions), and only one correction per rejected invoice.
KOD I on the original printout then leads to the corrected invoice.

See `examples/10-offline-invoicing.php`.
