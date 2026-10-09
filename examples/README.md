# Examples

Runnable, self-contained scripts. Each one focuses on a single idea and prints what it is doing.

## Run one in under a minute

```bash
git clone https://github.com/b44x/ksef-php.git && cd ksef-php
composer install
php examples/01-send-invoice.php
```

No account, token or certificate is needed. By default the examples run against the public KSeF **TEST**
environment and create a throw-away taxpayer (with a self-signed certificate) on every run. Nothing you do
there is real: test data is not legally binding and is wiped regularly.

The examples use Guzzle as the HTTP client (it is a dev dependency of this repository). In your own project you
can use any PSR-18 client.

## The tour

| # | File | You learn |
|---|------|-----------|
| 01 | `01-send-invoice.php` | The core flow: build, send, wait for the verdict, fetch the UPO, download the stored invoice |
| 02 | `02-invoice-kinds.php` | Simplified (UPR), advance (ZAL), settlement (ROZ) and correction (KOR) invoices |
| 03 | `03-batch.php` | Many invoices: one online session vs. a batch upload, results mapped back by hash |
| 04 | `04-timeouts-and-recovery.php` | A lost response in the middle of sending: how the SDK avoids duplicates |
| 05 | `05-download-and-sync.php` | Search, download, and incremental export with a cursor file |
| 06 | `06-certificates-and-tokens.php` | Moving from a one-off certificate to a KSeF certificate or token |
| 07 | `07-permissions.php` | Granting, listing and revoking access |
| 08 | `08-qr-codes.php` | Verification links (KOD I and KOD II) and rendering them as images |
| 09 | `09-error-handling.php` | The failures you will meet and what to do about each |

Start with 01, then 04 and 09: they cover what matters most in production.

## Using your own credentials

Set environment variables instead of editing code:

| Variable | Meaning |
|----------|---------|
| `KSEF_ENV` | `test` (default), `demo` or `production` |
| `KSEF_NIP` | Your NIP (the context you act for) |
| `KSEF_TOKEN` | A KSeF token, **or** |
| `KSEF_CERT`, `KSEF_KEY`, `KSEF_KEY_PASSPHRASE` | Paths to a certificate and private key (and the passphrase, if any) |

```bash
KSEF_ENV=test KSEF_NIP=1234563218 KSEF_TOKEN=... php examples/01-send-invoice.php
```

The examples send real documents and change real settings, so they refuse to run against `production` unless you also set `KSEF_ALLOW_PRODUCTION=1`. Prefer `test` or `demo`.
