# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- FA_RR (1) farmer purchase invoices: `RrInvoice` (with `RrInvoiceBuilder`, `RrLine`, `RrParty`, `RrPayment`, `RrCorrection`) including corrections (`KOR_VAT_RR`) and the amount in Polish words (`PolishAmountInWords`).
- Peppol documents (PEF (3), PEF_KOR (3)) and FA_RR as raw XML through `InvoiceDocument::fromXml()` with bundled schemas; batches check that all invoices share the declared form.
- Offline invoicing: `issueOfflineInvoice()` (XML plus KOD I and KOD II, no network needed), `sendOfflineInvoice()`, `sendTechnicalCorrection()`, `SendOptions` (`offlineMode`, `hashOfCorrectedInvoice`), `sendBatch(..., offline: true)`, `OfflineIssuer`; see `docs/OFFLINE.md`.
- Authorised entity on invoices (`PodmiotUpowazniony`): `AuthorizedEntity`, `AuthorizedEntityRole`, `InvoiceBuilder::authorizedEntity()`.
- Advanced permissions: entity authorisations (`grantAuthorization()`, `revokeAuthorization()`, `authorizations()`), indirect grants, subunit administrators, EU entity administrators and representatives, and the queries `subunitAdministrators()`, `euEntityPermissions()`, `entityRoles()`, `subordinateEntities()`, `attachmentStatus()`.
- Corrections of advance and settlement invoices (`KOR_ZAL`, `KOR_ROZ`): combine `InvoiceBuilder::correction()` with `advance()` / `settlement()`; `InvoiceType::isCorrection()`, `isAdvance()`, `isSettlement()`.
- Additional parties on invoices (`Podmiot3`): `ThirdParty`, `ThirdPartyRole` and `InvoiceBuilder::addThirdParty()`.

## [0.4.0] - 2026-10-09

### Added

- `B4x\Ksef\Testing\TestEnvironment::createTaxpayer()`: creates a disposable taxpayer with a self-signed certificate on the KSeF TEST environment (refuses any other environment).
- Eleven runnable, zero-configuration examples (`examples/`), an examples guide and `docs/GETTING-STARTED.md`.
- Invoice kinds: advance (`ZAL`, `AdvancePayment`), settlement (`ROZ`, `Settlement`) and simplified (`UPR`); `Decimal::dividedBy()`.
- Invoice export (`exportInvoices()`): encrypted package parts are downloaded, verified and decrypted into a ZIP file.
- Permissions: `grantPersonPermissions()`, `grantEntityPermissions()`, `revokePermission()`, `personPermissions()`, `myPermissions()`, `entityPermissions()`, `PermissionOperationException`.
- `contextLimits()`, `rateLimits()`, `authSessions()` and `revokeAuthSession()`.

## [0.3.0] - 2026-10-09

### Added

- KSeF certificates: `requestCertificate()` (limits, subject lookup, local key + CSR, enrolment, retrieval), `certificateLimits()`, `searchCertificates()`, `revokeCertificate()`; `IssuedCertificate::toCredentials()` / `toOfflineCertificate()`.

### Changed

- Empty JSON request bodies are sent as `{}`.

## [0.2.0] - 2026-10-09

### Fixed

- Flaky unit test and deprecations of old PSR packages on PHP 8.4+ (minimum `psr/http-factory` 1.1, dev `nyholm/psr7` 1.8.2).

### Added

- Batch sessions (`KsefClient::sendBatch()`, `waitForSession()`, `sessionInvoices()`, `failedSessionInvoices()`); needs `ext-zip`.
- QR verification links: `VerificationLinks` (KOD I, KOD II), `OfflineCertificate`, `Environment::qrBaseUrl()`.

## [0.1.0] - 2026-10-09

### Added

- Root namespace `B4x\Ksef` (package `b44x/ksef-php`).
- Automatic reconciliation of ambiguous submissions (`SubmissionRecoveryPolicy`): session lookup by hash, then bounded re-send of the identical document; `InvoiceSubmission::$recovered`, `SessionInvoice::assertStored()`.
- `waitForInvoice(..., untilStored: true)` waits for the documented `permanentStorageDate` before an accepted invoice is considered downloadable.
- `KsefClient` facade and builder (PSR-18 / PSR-17 / PSR-3 / PSR-20 based, no framework dependency).
- Authentication against KSeF API 2.0 with XAdES certificates and KSeF tokens, automatic token refresh.
- Per-session AES-256-CBC encryption with RSA-OAEP (SHA-256) key wrapping and rotation-aware public key cache.
- Typed FA(3) invoice model (standard and correction invoices), exact decimal arithmetic, validation,
  DOM based XML serialization and validation against the bundled official XSD files.
- Interactive sessions, bounded status polling, UPO retrieval, invoice download and metadata search.
- KSeF token management (generate, wait, revoke).
- Safe retry semantics and `SubmissionOutcomeUnknownException` with `findSubmission()` reconciliation.
- Exception hierarchy rooted at `B4x\Ksef\Exception\KsefException`.
- Unit and integration test suites, optional live tests against the KSeF TEST environment,
  PHPStan (max level, strict rules), PHP-CS-Fixer and GitHub Actions workflows.

[Unreleased]: https://github.com/b44x/ksef-php/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/b44x/ksef-php/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/b44x/ksef-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/b44x/ksef-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/b44x/ksef-php/releases/tag/v0.1.0
