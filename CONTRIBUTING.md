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

## Releasing

1. On `develop`, turn `## [Unreleased]` in `CHANGELOG.md` into `## [X.Y.Z] - date` (a fresh empty `[Unreleased]` above it), add the version links at the end and a section to `docs/UPGRADING.md`. These notes become the release notes: write them for users.
2. Fast-forward `main` to `develop` once CI is green, and run the live tests (`KSEF_LIVE=1 composer test:live`).
3. Tag and push: `git tag -a vX.Y.Z origin/main -m "vX.Y.Z" && git push origin vX.Y.Z`.

The `Release` workflow then publishes the GitHub release from the changelog section, marked as the latest release, with
the install command and, below 1.0.0, a note that the API may still change. It refuses a tag that is not on `main` or has no
notes. `php tools/changelog.php X.Y.Z` shows what it will publish.

## Following the KSeF specification

KSeF changes its API from time to time. `composer spec:check` compares the published OpenAPI specification with
`resources/spec/ksef-api-snapshot.json` (a weekly workflow runs it too). When it reports a change: read it, adapt the
SDK (and add tests), then refresh the snapshot with `php tools/spec.php snapshot > resources/spec/ksef-api-snapshot.json`
in the same commit.
