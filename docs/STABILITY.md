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
- `Testing\*` helpers (TEST environment only).

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
