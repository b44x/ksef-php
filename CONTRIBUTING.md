# Contributing

Thank you for helping to improve ksef-php. All project text (code, comments, documentation,
commit messages, issues) is written in English. Official KSeF terms and XML element names stay
in their original (Polish) form where the specification requires it.

## Setup

```bash
composer install
```

## Quality gates

```bash
composer test        # unit + integration tests (no network access)
composer analyse     # PHPStan at the maximum level
composer lint        # coding standards check (composer fix applies fixes)
composer check       # everything CI runs
```

Optional live tests against the public KSeF TEST environment are described in the README and
never run by default (`KSEF_LIVE=1 composer test:live`).

## Guidelines

- Keep the core framework agnostic. Integrations belong in separate packages.
- Every public class and method is part of the SDK contract: prefer `final`, `readonly` and
  small interfaces; do not add public API without tests and documentation.
- Never log or expose secrets (tokens, private keys, passwords).
- Network behaviour must be covered with deterministic PSR-18 fakes, not live calls.
- Follow Semantic Versioning and update `CHANGELOG.md`.

## Branching

- `main` holds released code and is tagged (`vX.Y.Z`).
- `develop` is the integration branch; open pull requests against it.
- Use short topic branches (`feat/...`, `fix/...`, `docs/...`).
