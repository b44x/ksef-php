# Architecture

ksef-php is a library, not an application. The core depends only on PSR interfaces (HTTP client,
HTTP factories, logger, clock) and PHP extensions (`dom`, `openssl`, `libxml`, `json`). It has no
knowledge of frameworks, databases, queues or configuration systems.

## Layers and responsibilities

| Namespace | Responsibility | Depends on |
| --- | --- | --- |
| `B4x\Ksef\KsefClient`, `KsefClientBuilder` | Public facade and wiring. Contains no protocol logic. | everything below |
| `B4x\Ksef\Session` | `OnlineSession`: encrypt, submit, poll, close. Encodes the submission safety rules. | `Api`, `Crypto`, `Invoice`, `Polling` |
| `B4x\Ksef\Api` | Thin typed wrappers around endpoints (`SessionApi`, `InvoiceApi`, `TokenApi`). Map JSON to value objects. | `Http`, `Status` |
| `B4x\Ksef\Auth` | Authentication flow (`Authenticator`), token lifecycle (`AccessTokenProvider`), credentials, `AuthApi`. | `Http`, `Crypto`, `Signing`, `Polling` |
| `B4x\Ksef\Invoice` | Domain model (`Invoice`, `Seller`, `Buyer`, `InvoiceLine`, `Money`, ...), validation, FA(3) serialization, `InvoiceDocument`. Pure PHP, no I/O. | `Support`, `Xml` |
| `B4x\Ksef\Crypto` | KSeF public keys (cached, rotation aware), RSA-OAEP, AES-256-CBC session encryption, digests. | `Http` (key download only) |
| `B4x\Ksef\Signing` | `XadesSigner` interface and the OpenSSL based implementation. | `ext-openssl`, `ext-dom` |
| `B4x\Ksef\Http` | PSR-18 `Transport` (URL/headers, JSON, retries, error mapping, redacted logging), `AuthorizedClient`. | PSR-7/17/18, PSR-3 |
| `B4x\Ksef\Polling` | `PollingPolicy`, `Poller`: bounded waiting with backoff. | PSR-20 clock, `Sleeper` |
| `B4x\Ksef\Status`, `B4x\Ksef\Exception`, `B4x\Ksef\Support`, `B4x\Ksef\Xml` | Result value objects, exception hierarchy, `Decimal`/`Nip`/`KsefNumber`, safe XML loading and XSD validation. | - |

Dependencies point inwards: the `Invoice` domain never touches HTTP, and `Http` never knows about
invoices. Each abstraction exists because something varies:

- `XadesSigner`: the private key may live in an HSM or remote signer.
- `PublicKeyProvider`: pin or share KSeF public keys across processes.
- `Sleeper` / PSR-20 `ClockInterface`: deterministic tests of retries, polling and token expiry.
- `Credentials`: the two authentication mechanisms KSeF offers today.
- PSR-18/17/3: the HTTP stack and logging are the application's choice.

## Request pipeline

```
KsefClient.sendInvoice()
  -> InvoiceFactory            Invoice | InvoiceDocument | XML  ->  verified InvoiceDocument (XSD)
  -> OnlineSession.send()      AES-256-CBC encrypt, hashes/sizes, ApiRequest
  -> SessionApi.sendInvoice()  typed endpoint call
  -> AuthorizedClient          bearer token from AccessTokenProvider; one re-auth on 401
  -> Transport                 URL, headers, JSON, retry policy, error mapping, PSR-18
```

## Failure semantics (the important part)

`ApiRequest` carries a `RetryMode` chosen per endpoint by the library, never by the caller:

- `Safe` (reads, public keys, challenge): retried on network errors, 429 and 500/502/503/504 with
  exponential backoff and jitter; `Retry-After` is honoured up to a cap.
- `RateLimitOnly` (every mutating call): retried only on 429. A 429 is returned before the request
  is processed, so repeating it cannot duplicate anything.
- `Never`: no retry.

Submitting an invoice additionally maps "no definitive answer" (transport failure or 5xx) to
`SubmissionOutcomeUnknownException`. KSeF's own duplicate detection (seller NIP + type + number,
status 440) is the idempotency mechanism; the SDK exposes it through `findSubmission()` and
`InvoiceStatus::isDuplicate()` rather than inventing an idempotency key the API does not have.

## Extensibility

- New API version: endpoint wrappers live in `Api`/`Auth`; base URL and `Environment` are data.
- New invoice schema (FA(4) ...): add a serializer and `FormCode` next to `Fa3Serializer`;
  `InvoiceDocument`/`OnlineSession` already carry the `FormCode` and reject mismatches.
- New authentication mechanism: add a `Credentials` implementation and a branch in `Authenticator`.
- Alternative crypto/signing backends: implement `XadesSigner` or `PublicKeyProvider`.

## Deliberate non-goals

No ORM/queue/storage integration, no framework bridges in core, no automatic persistence of
submissions (applications decide where to store `InvoiceSubmission`), no batch (ZIP) sessions yet.
