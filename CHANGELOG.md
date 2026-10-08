# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `KsefClient` facade and builder (PSR-18 / PSR-17 / PSR-3 / PSR-20 based, no framework dependency).
- Authentication against KSeF API 2.0 with XAdES certificates and KSeF tokens, automatic token refresh.
- Per-session AES-256-CBC encryption with RSA-OAEP (SHA-256) key wrapping and rotation-aware public key cache.
- Typed FA(3) invoice model (standard and correction invoices), exact decimal arithmetic, validation,
  DOM based XML serialization and validation against the bundled official XSD files.
- Interactive sessions, bounded status polling, UPO retrieval, invoice download and metadata search.
- KSeF token management (generate, wait, revoke).
- Safe retry semantics and `SubmissionOutcomeUnknownException` with `findSubmission()` reconciliation.
- Exception hierarchy rooted at `Ksef\Exception\KsefException`.
- Unit and integration test suites, optional live tests against the KSeF TEST environment,
  PHPStan (max level, strict rules), PHP-CS-Fixer and GitHub Actions workflows.
