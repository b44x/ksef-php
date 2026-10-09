# Getting started

A path from "never touched KSeF" to "sending invoices in production".

## 1. Try it without any account (5 minutes)

```bash
composer install
php examples/01-send-invoice.php
```

The [examples](../examples/README.md) create a disposable taxpayer on the public TEST environment, so you can
see the whole flow work before you read anything else. In your own code the same helper is available:

```php
use B4x\Ksef\Testing\TestEnvironment;

$taxpayer = TestEnvironment::createTaxpayer($http, $requestFactory, $streamFactory); // TEST only
$client = KsefClient::builder()
    ->environment(Environment::Test)
    ->httpClient($http, $requestFactory, $streamFactory)
    ->context($taxpayer->context())
    ->credentials($taxpayer->credentials())
    ->build();
```

Use it for integration tests of your own application, too.

## 2. Understand the three states of an invoice

1. **Accepted for processing** – `sendInvoice()` returned. KSeF has the document, nothing more is promised.
2. **Processed** – `waitForInvoice()` returned a status with a KSeF number (or a rejection, as `InvoiceRejectedException`).
3. **Stored** – `waitForInvoice(..., untilStored: true)`; only now can the invoice be downloaded and its UPO is final.

Never treat step 1 as success. Persist the submission reference as soon as you have it.

## 3. Decide how you authenticate

| Method | Good for | Notes |
|--------|----------|-------|
| Qualified/seal certificate (XAdES) | First login, small setups | You need the private key on the server |
| KSeF certificate | Production services | Request it once with a qualified login (example 06), then use it for all further logins |
| KSeF token | Simple integrations | Created in KSeF (or example 06), bound to permissions, revocable |

Keep credentials outside the code base (environment variables or a secret store).

## 4. Plan for failure

- A timeout while sending does **not** mean the invoice was not accepted. The SDK checks the session before
  re-sending and ends with `SubmissionOutcomeUnknownException` when it cannot tell: reconcile with
  `findSubmission()` (example 04).
- Duplicates are detected by KSeF (seller NIP + type + number). Status 440 means the invoice is already there.
- Handle `RateLimitException` (the SDK already honours `Retry-After`) and keep batches within the documented limits.
- Log with a PSR-3 logger (`->logger()`); the SDK never logs tokens, keys or invoice contents.

See example 09 for the exceptions you will meet.

## 5. Move to production checklist

- [ ] Run your flow on TEST, then on DEMO (`Environment::Demo`) with real-looking data.
- [ ] Credentials for production issued and stored as secrets; permissions limited to what you need.
- [ ] `->environment(Environment::Production)` – there is no default environment on purpose.
- [ ] TLS verification enabled; timeouts configured on your HTTP client.
- [ ] Submission references persisted before you wait; a job reconciles unknown outcomes.
- [ ] Invoice numbers are unique per seller (they are your idempotency key).
- [ ] UPO and the invoice XML are archived after the invoice is stored.
- [ ] Alerting on authentication, rate-limit and server errors.
- [ ] Optional: live tests against TEST in CI (`KSEF_LIVE=1`, see the README).

## Where next

- [README](../README.md) – full API reference by topic
- [Architecture](ARCHITECTURE.md) and [protocol notes](PROTOCOL.md) – how and why it works
