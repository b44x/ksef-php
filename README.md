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

## Quick start

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ksef\Auth\CertificateCredentials;
use Ksef\Auth\ContextIdentifier;
use Ksef\Environment;
use Ksef\Invoice\Address;
use Ksef\Invoice\Buyer;
use Ksef\Invoice\BuyerIdentifier;
use Ksef\Invoice\Invoice;
use Ksef\Invoice\InvoiceLine;
use Ksef\Invoice\Seller;
use Ksef\Invoice\VatRate;
use Ksef\KsefClient;
use Ksef\Support\Nip;

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
| Stored | `permanentStorageDate` set; the document can be downloaded | `isPermanentlyStored()`, `downloadInvoice($n, $wait)` |
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

Not modelled yet (send them as raw XML, see below): advance/settlement/simplified invoices, third parties
(`Podmiot3`), authorised entities, attachments, transport conditions, per-line discounts.

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

### Reliability: timeouts, retries and duplicates

- A **timeout or 5xx while sending an invoice never proves that it was not received.** The SDK does
  *not* retry such a request. It throws `SubmissionOutcomeUnknownException` carrying the session
  reference and invoice hash. Resolve it with `$ksef->findSubmission($sessionRef, $hash)`, or send the
  identical document again: KSeF detects duplicates globally by *seller NIP + invoice type + invoice
  number* and answers with status `440` plus `originalKsefNumber` instead of storing it twice.
- Only HTTP 429 is retried for mutating calls (the request was rejected before processing; `Retry-After`
  is honoured up to a cap). Read-only calls are also retried on network errors and 500/502/503/504.
- Polling is always bounded (`PollingPolicy`); a `PollingTimeoutException` means "unknown yet", not "failed".

## Checking status

```php
$invoice = $ksef->invoiceStatus($submission);          // one request
$invoice = $ksef->waitForInvoice($submission);         // polls until terminal; may be a rejection

$invoice->status->isAccepted();                        // code 200
$invoice->status->isRejected();                        // code >= 400
$invoice->status->isDuplicate();                       // 440 -> originalKsefNumber()
$invoice->assertAccepted();                            // throws InvoiceRejectedException with KSeF's explanation
```

## Retrieving UPO, invoices and searching

```php
$upo = $ksef->invoiceUpo($submission);                 // hash announced by KSeF is verified ($upo->verifyHash())
$status = $ksef->sessionStatus($sessionRef);           // $status->upoPages -> $ksef->sessionUpo($sessionRef, $page->referenceNumber)

$invoice = $ksef->downloadInvoice($ksefNumber, new PollingPolicy(timeoutSeconds: 60.0));
//        ^ a just-accepted invoice answers HTTP 406 for a few seconds; passing a policy waits for it

$page = $ksef->searchInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, $to);
```

## Error handling

All exceptions extend `Ksef\Exception\KsefException`.

| Exception | Meaning | Typical reaction |
| --- | --- | --- |
| `ValidationException` (`SerializationException`) | Local validation failed; **nothing was sent**; `->violations` lists all problems | Fix the data |
| `ApiException` + `AuthenticationException` (401), `AuthorizationException` (403, `->reasonCode`), `RateLimitException` (429, `->retryAfterSeconds`), `ServerException` (5xx) | KSeF answered with an error; `->httpStatus`, `->ksefCode()`, `->errors`, `->traceId` | Per status; report `traceId` to KSeF support |
| `InvoiceRejectedException` | Processing ended with a failure status (`->status`) | Correct the invoice; for 440 look at `originalKsefNumber()` |
| `SubmissionOutcomeUnknownException` | Network/5xx during submission | `findSubmission()` or re-send identical document |
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
