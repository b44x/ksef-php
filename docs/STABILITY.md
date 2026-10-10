# Stability and versioning

The SDK follows [Semantic Versioning](https://semver.org/). From 1.0 on, a change that breaks code written against the
**public API** only happens in a new major version; minor and patch releases keep it working.

## What is the public API

Covered by the promise:

- `KsefClient`, `KsefClientBuilder`, `Environment`.
- The exceptions in `B4x\Ksef\Exception` (class names, the hierarchy, public properties).
- The value objects you build or receive: `Invoice` and everything it is made of, `Rr\*`, `Offline\OfflineInvoice`,
  `Permissions\*` (subjects, grants, enums), `Collective\*`, `Status\*`, `Certificates\*` results, `Limits\*`,
  `Qr\VerificationLinks` / `OfflineCertificate`, `Session\OnlineSession`, `Session\SendOptions`.
- Configuration: `Auth` credentials and `ContextIdentifier`, `Http\RetryPolicy`, `Polling\PollingPolicy`,
  `Session\SubmissionRecoveryPolicy`.
- Extension points: `Signing\XadesSigner`, `Crypto\PublicKeyProvider`, `Http\Sleeper`.
- `Support\Decimal`, `Nip`, `KsefNumber`.
- `Pagination\Page`: what every listing returns (`items`, `hasMore`, `continuationToken`; iterate it or count it).
- A few result and filter types that live in the `Api` namespace for historical reasons are public: `Api\GeneratedToken`,
  `TokenStatus`, `TokenPermission`, `InvoiceMetadata`, `InvoiceMetadataPage`, `InvoiceSubjectType`, `InvoiceDateType`.
  They will not move before a major version.
- `Export\ExportedPackage`, `Offline\OfflineIssuer`, `Auth\AllowedIps` / `AuthSession`, `Invoice\InvoiceBuilder`,
  `Rr\RrInvoiceBuilder`, `Peppol\PeppolProvider`, `Signing\OpenSslXadesSigner`, `Crypto\ApiPublicKeyProvider`.
- `Testing\*` helpers (TEST environment only).

PSR interfaces that appear in signatures (`Psr\Http\Client`, `Psr\Http\Message`, `Psr\Log`, `Psr\Clock`) are part of the
contract: supporting a new major version of one of them needs a major version of this library only if the old one stops working.

Constructors of **response objects** (`Status\*`, `Permissions\PermissionGrant` and similar, `CertificateInfo`, ...) are
for the SDK's own use: read their properties, do not rely on the order or number of constructor arguments, and do not
call `fromPayload()`. The same goes for `KsefClient::__construct()`; build the client with `KsefClient::builder()`.

Tax-law and KSeF vocabulary enums (`VatRate`, `Gtu`, `Permission`, `EntityPermissionType`, `InvoiceType`, `PaymentMethod`,
`Environment`, ...) gain cases when the law or KSeF does; that is not a breaking change (see below).

`Signing\XadesSigner`, `Crypto\PublicKeyProvider` and `Http\Sleeper` are meant to be implemented by you. New capabilities
will arrive as new interfaces, not as new methods on these. `Auth\Credentials` cannot be implemented outside the SDK.

Constructors with many optional parameters (`Invoice`, `InvoiceLine`, `Seller`, ...) are meant to be used through their
builders and static factories (`Invoice::builder()`, `InvoiceLine::of()`); if you call a constructor directly, use named
arguments, because new optional parameters are added at the end and are not a breaking change.

**Not** covered: anything marked `@internal` (the `*Api` endpoint wrappers, `Http\Transport` and friends, serializers,
validators, XML helpers, the poller, the batch packager). They may change in any release; use `KsefClient` instead.

## What does not count as a breaking change

- New methods, classes, enum cases and optional parameters. (If you implement our interfaces or `match` exhaustively
  over our enums, plan for additions.)
- Stricter local validation that merely reproduces a rule KSeF already enforces: the request would have been refused
  anyway, only later and with a less helpful message.
- Changes that follow the KSeF API or the FA(3) schema when KSeF itself changes them. KSeF can break integrations on
  its own schedule; `tools/spec.php` watches for that and a release follows.
- Message texts of exceptions (match on the class, not the text).

## Deprecations

A feature to be removed is first marked `@deprecated` (and triggers `E_USER_DEPRECATED` where practical) for at
least one minor release, and is listed in the changelog.
