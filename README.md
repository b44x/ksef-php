# ksef-php

Framework-agnostic PHP SDK for the Polish **National e-Invoice System (KSeF) API 2.0**.

It takes care of everything between your application and KSeF: authentication (XAdES certificate
or KSeF token), token refresh, client-side encryption, FA(3) invoice XML generation with XSD
validation, interactive sessions, asynchronous status polling, UPO retrieval, invoice download and
search. Your code works with domain objects, not with endpoints, XML or cryptography.

- Works with any PSR-18 HTTP client and PSR-3 logger; no framework required
- Strict types, immutable value objects, enums, no global state
- Safe by design: submissions are never blindly retried, duplicates are detected by KSeF, secrets are never logged
- Verified against the official KSeF OpenAPI specification (v2.8.1) and exercised end to end on the public TEST environment

> **Status:** pre-1.0. The public API is documented below and covered by tests, but may still receive
> adjustments before `1.0.0`. See [CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP 8.2 or newer
- Extensions: `dom`, `json`, `libxml`, `mbstring`, `openssl`
- A PSR-18 HTTP client plus PSR-17 factories (for example Guzzle, or Symfony HttpClient with `nyholm/psr7`)

## Installation

```bash
composer require b44x/ksef-php guzzlehttp/guzzle
```

`guzzlehttp/guzzle` is only one possible HTTP client; the SDK itself depends on the PSR interfaces only.

## Try it in 60 seconds

```bash
git clone https://github.com/b44x/ksef-php.git && cd ksef-php && composer install
php examples/01-send-invoice.php
```

This needs no account: it runs against the public KSeF TEST environment with a throw-away taxpayer (the examples
use Guzzle, a dev dependency). See [examples/](examples/README.md) for the thirteen guided examples and
[docs/GETTING-STARTED.md](docs/GETTING-STARTED.md) for the path to production.

## Quick start

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Support\Nip;

$factory = new HttpFactory();
$ksef = KsefClient::builder()
    ->environment(Environment::Test)                       // there is no default: choose explicitly
    ->httpClient(new Client(['timeout' => 30, 'connect_timeout' => 10]), $factory, $factory)
    ->context(ContextIdentifier::nip('5265877635'))        // on whose behalf you work
    ->credentials(CertificateCredentials::fromPemFiles('/secure/cert.pem', '/secure/key.pem'))
    ->build();

$invoice = Invoice::builder()
    ->number('FV/2026/06/001')
    ->issueDate('2026-06-01')
    ->seller(new Seller(Nip::of('5265877635'), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1', '00-001 Warszawa')))
    ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('1234563218')), 'Sample Buyer S.A.'))
    ->addLine(InvoiceLine::of('Consulting', '10', 'h', '150.00', VatRate::Rate23))
    ->build();                                             // throws ValidationException listing every problem

$submission = $ksef->sendInvoice($invoice);                // accepted for processing – NOT yet approved
$result = $ksef->waitForInvoice($submission)->assertAccepted();

echo $result->ksefNumber;                                  // e.g. 5265877635-20260601-0100001AF629-15
$upo = $ksef->invoiceUpo($submission);                     // signed confirmation of receipt (XML)
```

Runnable, fully typed variants live in [`examples/`](examples).

## Concepts you need to know

KSeF processing is **asynchronous**, and the SDK models that honestly:

| Step | Meaning | SDK |
| --- | --- | --- |
| Request created | `Invoice` validated, XML built, XSD-checked | `Invoice::builder()->build()`, `InvoiceDocument` |
| Request sent / accepted | KSeF returned HTTP 202: *accepted for processing* | `InvoiceSubmission` |
| Processed | status 200 and a KSeF number, or a rejection (4xx codes) | `waitForInvoice()` → `SessionInvoice` |
| Stored | `permanentStorageDate` set; the document can be downloaded | `waitForInvoice(..., untilStored: true)`, `isPermanentlyStored()` |
| UPO available | signed confirmation of receipt | `invoiceUpo()` |

## Configuration

### Environments

`Environment::Test` (self-signed certificates allowed, no real data), `Environment::Demo`
(pre-production) and `Environment::Production`. Use `baseUrl()` for a mock server.

### Authentication

KSeF issues a short-lived access token (JWT) plus a refresh token. The SDK performs the full flow
transparently on first use: *challenge → proof of identity → status polling → token redemption*,
refreshes the access token before it expires, and falls back to a fresh authentication when the
refresh token is rejected. A `401` answer triggers exactly one re-authentication and retry.

Two kinds of credentials are supported:

```php
// 1. Certificate (qualified signature/seal, KSeF certificate, or a self-signed one on TEST).
//    The AuthTokenRequest is signed with XAdES-BES (RSA >= 2048 bit or EC P-256+).
CertificateCredentials::fromPemFiles($certPath, $keyPath, $optionalPassphrase);
CertificateCredentials::fromPkcs12(file_get_contents($p12), $password);

// 2. KSeF token (generated in the KSeF application or with $ksef->generateToken()).
//    Sent as RSA-OAEP(SHA-256) encrypted "token|challengeTimestampMs".
new KsefTokenCredentials(getenv('KSEF_TOKEN'));
```

Use `CertificateCredentials` with a custom `XadesSigner` to keep the private key in an HSM or a remote
signing service. Restrict where an access token may be used with `->allowedIps(new AllowedIps([...]))`.

> Qualified certificates are verified by KSeF through OCSP/CRL on PRE/PROD, so "authentication in
> progress" can last a while. The wait is bounded and configurable (`authenticationPolling()`).
> KSeF recommends authenticating with a *KSeF certificate* in production.

### Builder options

```php
KsefClient::builder()
    ->environment(...)            // or ->baseUrl('https://...')
    ->httpClient($client, $requestFactory, $streamFactory)
    ->context(...)->credentials(...)
    ->logger($psr3Logger)         // optional; never receives secrets or document contents
    ->retryPolicy(new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 0.5, maxDelaySeconds: 30.0))
    ->polling(new PollingPolicy(timeoutSeconds: 120.0))      // default for waitFor*()
    ->authenticationPolling(...)->allowedIps(...)->publicKeyProvider(...)->clock(...)->sleeper(...)
    ->build();
```

Timeouts and TLS verification are properties of your PSR-18 client; configure sensible timeouts and keep
certificate verification enabled.

## Creating an invoice

`Invoice` is an immutable, always-valid aggregate. Amounts are exact decimals (`Decimal`, strings
in the API) – floats are never accepted. Net-priced lines; tax is computed per rate bucket on the
summed net value and rounded half away from zero, as the VAT Act prescribes.

```php
$invoice = Invoice::builder()
    ->number('FV/2026/06/002')->issueDate('2026-06-01')->saleDate('2026-05-31')->issuePlace('Warszawa')
    ->currency('EUR')->exchangeRate('4.3210')                // foreign currency: VAT in PLN is derived
    ->seller($seller)->buyer($buyer)
    ->addLine(InvoiceLine::of('Software licence', '1', 'szt.', '1000.00', VatRate::Rate23, 'EUR'))
    ->addLine(InvoiceLine::of('Training', '2', 'h', '80.00', VatRate::Exempt, 'EUR'))
    ->annotations(new Annotations(splitPayment: true, exemptionBasis: 'Art. 43 ust. 1 pkt 29 lit. b ustawy o VAT'))
    ->payment(Payment::dueOn(new DateTimeImmutable('2026-06-15'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
    ->build();
```

Supported: standard (`VAT`) and correction (`KOR`) invoices, one seller, one buyer (Polish NIP, EU VAT,
foreign tax id or none), all common VAT treatments (23/22/8/7/5 %, 0 % variants, `zw`, `oo`, `np`),
GTU codes, payment details, annotations and a footer. Corrections: mark the original state with
`InvoiceLine::asBefore()` and add the corrected lines; totals become differences automatically.

**Other invoice forms.** Besides FA(3) the SDK handles FA_RR (1) farmer purchase invoices with a typed model
(`RrInvoice`, see `examples/11-farmer-rr-invoice.php`) and accepts Peppol documents (PEF (3) invoices and PEF_KOR (3)
credit notes) as verified raw XML: `InvoiceDocument::fromXml($ublXml)` recognises the form from the root element
and checks it against the bundled schema. Sending PEF invoices as a Peppol provider is verified end to end on TEST
(`examples/13-peppol-invoice.php`, [docs/PEPPOL.md](docs/PEPPOL.md)), credit notes (PEF_KOR) included.

**Optional extras** (all in `examples/12-rich-invoice.php`): `InvoiceLine::withDiscount()`, `deliveredOn()`,
`withProcedure()`, `withExcise()`; `addInfo()` remarks, `addWarehouseDocument()`; `additionalSettlement()` (charges and
deductions); `Payment::partlyPaid()`, early-payment discount and other payment methods; `terms()` (contracts, orders,
batch numbers, delivery terms); margin schemes and related-party flags in `Annotations`; and a structured
`attachment()`. KSeF accepts attachments only in batch sessions and only from taxpayers who gave their consent
beforehand (`attachmentStatus()`); the SDK refuses to send them in an interactive session.

Additional parties (`Podmiot3`: recipient, payer, factor, ...) are added with `addThirdParty(ThirdParty::of(ThirdPartyRole::Recipient, ...))`.

The typed model covers every part of FA(3), including the intra-Community supply of new means of transport
(`Annotations::$newTransport`). Seller/buyer data before a correction (`Podmiot1K`/`Podmiot2K`) go into `Correction`, transports and the
contractual currency into `TransactionTerms`, EORI numbers, the JST/VAT-group flags and correspondence addresses
into `Seller` and `Buyer`.

Special kinds:

```php
// Advance invoice (ZAL): the tax is taken out of the gross payment; the lines describe the order.
Invoice::builder()->...->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, $paidOn))
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))->build();

// Final invoice (ROZ): full sale; P_15 is the remainder after the advances.
Invoice::builder()->...->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceKsefNumber)], Money::pln('1230.00')))
    ->addLine(...)->build();

// Correction of an advance invoice (KOR_ZAL): combine correction() and advance(); the advance amount is the
// CHANGE of the payment (negative when it decreases), lines are the order before (asBefore) and after.
Invoice::builder()->...->correction($correction)->advance(new AdvancePayment(Money::pln('-615.00'), VatRate::Rate23, $paidOn))
    ->addLine($orderLine->asBefore())->addLine($newOrderLine)->build();

// Correction of a final invoice (KOR_ROZ): combine correction() and settlement(); before/after lines.
Invoice::builder()->...->correction($correction)->settlement($settlement)->addLine($line->asBefore())->addLine($newLine)->build();

// Simplified invoice (UPR): up to PLN 450 / EUR 100, buyer identified by NIP.
Invoice::builder()->...->simplified()->addLine(...)->build();
```

**Raw XML escape hatch.** Any FA(3) document can be sent after verification:

```php
$document = InvoiceDocument::fromXml($xml);   // size, UTF-8/BOM, DOCTYPE, PI, namespace and XSD checks
$ksef->sendInvoice($document);
```

## Sending invoices

```php
$submission = $ksef->sendInvoice($invoice);                  // one invoice, own short-lived session

$session = $ksef->openOnlineSession();                       // many invoices, one session (<= 12 h, <= 10,000 invoices)
$a = $session->send($invoiceA);
$b = $session->send($invoiceB);
$session->close();                                           // starts the aggregate UPO generation
$status = $session->waitUntilFinished();                     // SessionStatus incl. UPO page references
```

Each invoice is encrypted with a per-session AES-256-CBC key which is wrapped with the Ministry's
RSA key (OAEP, SHA-256). Hashes and sizes of plaintext and ciphertext are computed for you.

### Batch sessions

For large volumes send a batch: the SDK validates every invoice, builds a ZIP (temporary file, never fully in
memory), splits it into parts of at most 100 MB (max. 50 parts, 10,000 invoices), encrypts each part, uploads
them to the pre-signed URLs KSeF returns and closes the session. Requires `ext-zip`.

```php
$batch = $ksef->sendBatch($invoices);                         // iterable of Invoice | InvoiceDocument | XML string
$status = $ksef->waitForSession($batch->sessionReference);    // SessionStatus (aggregate counts, UPO pages)
$page = $ksef->sessionInvoices($batch->sessionReference);     // per invoice: ksefNumber, status, invoiceHash
// $batch->invoiceHashes lets you map results back to your own documents.
```

### Permissions

```php
$ksef->grantPersonPermissions(PersonSubject::byPesel($pesel, 'Anna', 'Nowak'), [Permission::InvoiceRead, Permission::InvoiceWrite], 'accountant');
$ksef->grantEntityPermissions(Nip::of('5265877635'), 'Partner sp. z o.o.', ['InvoiceRead' => true]);   // may delegate: true
foreach ($ksef->personPermissions(grantedByMe: true)['permissions'] as $grant) { /* $grant->id, ->scope, ->holder */ }
$ksef->revokePermission($grant->id);
```

Grants and revocations are asynchronous in KSeF; these calls wait for the operation and throw
`PermissionOperationException` (with KSeF's status code) when it is refused. Also available: `myPermissions()`,
`entityPermissions()`.

Special arrangements have their own calls, all asynchronous in KSeF and awaited by the SDK:

```php
// Entity-level authorisations: self-invoicing, RR, tax representative, Peppol
$ksef->grantAuthorization(Nip::of('5265877635'), EntityAuthorizationType::SelfInvoicing, 'Partner sp. z o.o.', 'self-billing');
$ksef->authorizations(AuthorizationDirection::Granted);   // ...::Received
$ksef->revokeAuthorization($authorization->id);           // not revokePermission(): different endpoint

// Accounting office: a person works in the contexts of your customers
$ksef->grantIndirectPermissions($person, [EntityPermissionType::InvoiceRead], 'staff', IndirectTarget::allPartners());

// Subordinate units (local government, VAT groups) and EU entities
$ksef->grantSubunitAdministrator($person, SubunitContext::internalId('5265877635-12345'), 'branch admin');
$ksef->grantEuEntityAdministrator(EuEntitySubject::person(PersonSubject::byFingerprint(...)), '5265877635-DE123456789', 'Muster GmbH', 'Berlin', 'admin');
$ksef->grantEuEntityRepresentative(EuEntitySubject::entity($fingerprint, 'Seal GmbH', 'Berlin'), [EuEntityPermissionType::InvoiceWrite], 'rep');

// Reading: subunitAdministrators(), euEntityPermissions(), entityRoles(), subordinateEntities(), attachmentStatus()
```

### Export, limits and logins

```php
// Decrypted ZIP of {ksefNumber}.xml + _metadata.json; page through time with continueFrom (PermanentStorage).
$package = $ksef->exportInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, null, '/tmp/export.zip');
if ($package->isTruncated) { $from = $package->continueFrom; /* export again */ }

$ksef->contextLimits();   // max invoices / sizes per session type
$ksef->rateLimits();      // ['invoiceSend' => RateLimit(perSecond, perMinute, perHour), ...]
$ksef->authSessions();    // active logins; $ksef->revokeAuthSession($ref) or revokeAuthSession() for the current one
```

### Collective identifiers and Peppol

```php
// One payment reference for many invoices of the same seller
$id = $ksef->createCollectiveIdentifier([new CollectiveInvoice($ksefNumber1, Money::pln('123.00'), 'May'), new CollectiveInvoice($ksefNumber2)]);
$page = $ksef->collectiveIdentifiers($from, $to);                      // paged; pass $page->continuationToken for the next page
$ksef->collectiveIdentifierInvoices([$id]);                            // the invoices (payment details only for entitled parties)
$ksef->collectiveIdentifiersOf($ksefNumber1);                          // which identifiers an invoice belongs to

$ksef->peppolProviders();                                             // registered Peppol service providers
$ksef->subjectLimits();                                               // certificate enrolment / certificate limits of the taxpayer
```

### KSeF certificates

KSeF issues its own certificates (type `Authentication` for logging in, type `Offline` for signing KOD II links).
`requestCertificate()` runs the whole enrolment: limit check, subject lookup, local key + CSR generation
(EC P-256 by default, RSA 2048 optional), submission, waiting, retrieval. **The private key exists only in the
returned object: store it in a secret manager.** The session must be authenticated with a *signature*
(`CertificateCredentials`); KSeF refuses enrolment from token sessions.

```php
$cert = $ksef->requestCertificate('billing service', CertificateType::Authentication);   // KeyType::EcP256
$credentials = $cert->toCredentials();               // log in with it from now on (no OCSP/CRL delay)

$offline = $ksef->requestCertificate('offline qr', CertificateType::Offline);
$signer = $offline->toOfflineCertificate();          // for VerificationLinks::certificateUrl()

$ksef->certificateLimits();  $ksef->searchCertificates(CertificateType::Offline);  $ksef->revokeCertificate($serial);
```

### QR codes

Offline invoicing (issue without KSeF, deliver later, technical correction) has its own guide: [docs/OFFLINE.md](docs/OFFLINE.md).

`VerificationLinks` builds the links for the QR codes of an invoice visualisation (the SDK does not draw the
image; feed the link to any ISO/IEC 18004 library such as `bacon/bacon-qr-code`):

```php
$links = new VerificationLinks(Environment::Production);
$url = $links->invoiceUrl($sellerNip, $issueDate, $document);            // KOD I, every invoice
$label = $links->label($ksefNumber);                                      // KSeF number, or "OFFLINE"
$url2 = $links->certificateUrl($context, $sellerNip, $document->hash(),   // KOD II, offline invoices only
    new OfflineCertificate($certPem, $keyPem));                           // KSeF "Offline" certificate
```

### Reliability: timeouts, retries and duplicates

A timeout or 5xx while an invoice is being sent never proves that KSeF did not receive it. The SDK
therefore reconciles automatically instead of guessing (`SubmissionRecoveryPolicy`, on by default):

1. it searches the session for the document's hash; if KSeF has it, the submission is returned with
   `$submission->recovered === true` and nothing is sent again;
2. otherwise the *identical* document is re-sent (default: up to 2 times, 1 s then 2 s backoff). This
   cannot create a second invoice: KSeF detects duplicates globally by *seller NIP + invoice type +
   invoice number* and answers `440` with `originalKsefNumber`;
3. if that stays inconclusive, `SubmissionOutcomeUnknownException` is thrown (session reference and hash
   included; `findSubmission()` can still resolve it later).

A refusal (4xx) at any point is final and propagates unchanged. Tune or switch off with
`->submissionRecovery(new SubmissionRecoveryPolicy(maxResends: 3))` / `SubmissionRecoveryPolicy::disabled()`.
For recovered submissions use `assertStored()` (also accepts a 440 duplicate: the document is in KSeF).

Other calls: only HTTP 429 is retried for mutating requests (`Retry-After` honoured up to a cap);
read-only calls are also retried on network errors and 500/502/503/504. Polling is always bounded
(`PollingPolicy`); a `PollingTimeoutException` means "unknown yet", not "failed".

## Checking status

```php
$invoice = $ksef->invoiceStatus($submission);          // one request
$invoice = $ksef->waitForInvoice($submission);         // polls until terminal; may be a rejection

$invoice->status->isAccepted();                        // code 200
$invoice->status->isRejected();                        // code >= 400
$invoice->status->isDuplicate();                       // 440 -> originalKsefNumber()
$invoice->assertAccepted();                            // throws InvoiceRejectedException with KSeF's explanation
$invoice->assertStored();                              // like assertAccepted(), but a 440 duplicate counts (already in KSeF)

// An accepted invoice becomes downloadable once `permanentStorageDate` is set:
$ksef->waitForInvoice($submission, null, untilStored: true);
```

## Retrieving UPO, invoices and searching

```php
$upo = $ksef->invoiceUpo($submission);                 // hash announced by KSeF is verified ($upo->verifyHash())
$status = $ksef->sessionStatus($sessionRef);           // $status->upoPages -> $ksef->sessionUpo($sessionRef, $page->referenceNumber)

$invoice = $ksef->downloadInvoice($ksefNumber);
//   Wait with waitForInvoice(..., untilStored: true) first. As a safety net, a download that still answers
//   HTTP 406 (observed for a few seconds after acceptance) raises InvoiceNotAvailableException, or is awaited
//   when you pass a PollingPolicy as second argument.

$page = $ksef->searchInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, $to);
```

## Error handling

All exceptions extend `B4x\Ksef\Exception\KsefException`.

| Exception | Meaning | Typical reaction |
| --- | --- | --- |
| `ValidationException` (`SerializationException`) | Local validation failed; **nothing was sent**; `->violations` lists all problems | Fix the data |
| `ApiException` + `AuthenticationException` (401), `AuthorizationException` (403, `->reasonCode`), `RateLimitException` (429, `->retryAfterSeconds`), `ServerException` (5xx) | KSeF answered with an error; `->httpStatus`, `->ksefCode()`, `->errors`, `->traceId` | Per status; report `traceId` to KSeF support |
| `InvoiceRejectedException` | Processing ended with a failure status (`->status`) | Correct the invoice; for 440 look at `originalKsefNumber()` |
| `SubmissionOutcomeUnknownException` | Network/5xx during submission and automatic reconciliation was inconclusive | `findSubmission()` later, or re-send the identical document |
| `TransportException` / `MalformedResponseException` | No usable HTTP answer / contract violation | Retry read operations later |
| `PollingTimeoutException` | Waiting budget exhausted; operation may still complete | Poll again later |
| `InvoiceNotAvailableException` | Accepted invoice not stored yet (HTTP 406) | Wait and retry |
| `SigningException`, `EncryptionException`, `ConfigurationException`, `SessionException` | Local cryptography, wiring or session state problems | Fix configuration |

Messages and exception data never contain tokens, private keys or request bodies.

## Security

- Secrets are injected, never hardcoded. Load tokens and keys from a secret store or environment.
  Secret-holding objects hide their values from `var_dump()`/logs and are marked `#[\SensitiveParameter]`.
- Tokens live in memory only. The SDK never writes credentials to disk or logs.
- Private keys: RSA < 2048 bits are rejected; the key must match the certificate.
- XML: documents containing a `DOCTYPE` are refused (no XXE/entity expansion), network access is disabled
  in libxml, and signed/serialized documents are produced with DOM (no string concatenation).
- TLS: configure certificate verification on your PSR-18 client; the SDK refuses non-HTTPS base URLs
  (localhost excepted) and non-HTTPS download links.
- Cryptography uses OpenSSL and phpseclib; no algorithm is implemented in this library.
- `Environment::Test` accepts self-signed certificates; never use production data there.

Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Framework integration

The core has no framework dependency. Wire it in your container, for example:

```php
// Laravel service provider / Symfony service definition: bind KsefClient as a shared service built
// with your framework's PSR-18 client, PSR-3 logger and a secret from your configuration.
```

Dedicated bridges can live in separate packages; the PSR interfaces are the integration points.

## Architecture

```
KsefClient (facade)
 ├─ Auth\            Authenticator, AccessTokenProvider, credentials, AuthApi
 ├─ Session\         OnlineSession (encrypt, send, poll, close)
 ├─ Api\             SessionApi, InvoiceApi, TokenApi  – thin typed endpoint wrappers
 ├─ Invoice\         Domain model, validator, Fa3Serializer, InvoiceDocument (XSD-checked)
 ├─ Crypto\          Public key provider, RSA-OAEP, AES-256-CBC, digests
 ├─ Signing\         XadesSigner interface + OpenSSL implementation
 ├─ Http\            Transport (PSR-18), retry policy, error mapping, AuthorizedClient
 ├─ Polling\         PollingPolicy, Poller
 └─ Status\, Exception\, Support\ (Decimal, Nip, KsefNumber), Xml\
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/PROTOCOL.md](docs/PROTOCOL.md) for design decisions
and the protocol facts this implementation relies on.

## Testing

```bash
composer install
composer test        # unit + integration (deterministic PSR-18 fakes, no network)
composer analyse     # PHPStan, level max + strict rules
composer lint        # php-cs-fixer (composer fix applies)
composer check       # all of the above plus composer validate
```

**Live tests** (opt-in) run the complete lifecycle against the public KSeF TEST environment: they create a
throw-away taxpayer through the TEST-only `/testdata` API, authenticate with a self-signed certificate and
with a freshly generated KSeF token, send an invoice, wait for acceptance, fetch the UPO, download the
invoice and verify duplicate detection. No credentials are needed:

```bash
KSEF_LIVE=1 composer test:live
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Run `composer install && composer check` before opening a pull request.

## License

MIT. See [LICENSE](LICENSE). The bundled XSD schemas are published by the Polish Ministry of Finance
(see `resources/schemas`).
