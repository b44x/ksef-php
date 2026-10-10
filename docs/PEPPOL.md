# Peppol (PEF) invoices

KSeF accepts invoices from the Peppol network in the UBL formats **PEF (3)** (invoice) and **PEF_KOR (3)** (credit
note). They are sent by a **Peppol service provider**, not by the company itself. The SDK covers the transport; the
UBL document comes from your Peppol tooling.

## The roles

| Who | Does | KSeF concept |
|-----|------|--------------|
| The company (seller) | authorises the provider once | `PefInvoicing` authorisation, granted to the provider's Peppol ID |
| The provider | signs in with its own certificate and sends the documents | context type `PeppolId` |

## Provider side

```php
$provider = KsefClient::builder()
    ->environment(Environment::Production)
    ->httpClient(...)
    ->context(ContextIdentifier::peppolId('PPL123456'))        // the provider's Peppol ID
    ->credentials(CertificateCredentials::fromPemFiles($certPath, $keyPath))   // the dedicated Peppol provider certificate
    ->build();

$session = $provider->openOnlineSession(FormCode::pef());       // FormCode::pefCorrection() for credit notes
$document = InvoiceDocument::fromXml($ublXml);                  // recognised as PEF from the root element, checked against the XSD
$submission = $session->send($document);
$provider->waitForInvoice($submission, untilStored: true)->assertAccepted();
$session->close();
```

KSeF registers a provider automatically the first time it signs in. The invoice is numbered under the **seller's**
NIP (taken from the UBL document), not the provider's.

## Company side

After the provider has signed in once (a company cannot authorise an unknown provider):

```php
$company->grantAuthorization('PPL123456', EntityAuthorizationType::PefInvoicing, 'Provider name', 'PEF invoicing');
$company->listPeppolProviders();       // the providers registered in KSeF
```

## Try it on TEST

`examples/13-peppol-invoice.php` plays both roles with throw-away identities
(`TestEnvironment::createPeppolProvider()` creates a provider whose self-signed certificate has the Peppol ID as its
common name, which is what KSeF TEST accepts). The sample UBL invoice is `examples/fixtures/pef-invoice.xml`.

## Status

- PEF (3) invoices: verified end to end against KSeF TEST (provider sign-in, authorisation, send, acceptance).
- PEF_KOR (3): verified end to end with the Ministry of Finance's own correction template
  (`examples/fixtures/pef-correction.xml`, see `NOTICE.md` there). A bare-bones UBL credit note is **not** enough:
  KSeF expects the Polish extension (`ext:UBLExtensions` with the data of the corrected invoice and the differences),
  so build corrections from that template. It carries an attachment, so the seller needs the attachment consent.
- The production provider certificate format is issued by the Peppol authority; only the TEST variant is documented
  publicly.
