# Protocol notes and implementation decisions

Source of truth: the official KSeF API 2.0 OpenAPI specification (version 2.8.1, fetched from
`https://api.ksef.mf.gov.pl/docs/v2/openapi.json` and the TEST twin) and the integrator
documentation at <https://github.com/CIRFMF/ksef-docs>. Where this library had to interpret
something, it is listed here.

## Endpoints used

| Area | Endpoints |
| --- | --- |
| Public keys | `GET /security/public-key-certificates` |
| Authentication | `POST /auth/challenge`, `POST /auth/xades-signature`, `POST /auth/ksef-token`, `GET /auth/{ref}`, `POST /auth/token/redeem`, `POST /auth/token/refresh` |
| Sessions | `POST /sessions/online`, `POST /sessions/online/{ref}/invoices`, `POST /sessions/online/{ref}/close`, `GET /sessions/{ref}`, `GET /sessions/{ref}/invoices`, `GET /sessions/{ref}/invoices/{inv}`, `.../upo`, `GET /sessions/{ref}/upo/{upoRef}` |
| Invoices | `GET /invoices/ksef/{ksefNumber}`, `POST /invoices/query/metadata` |
| Tokens | `POST /tokens`, `GET /tokens/{ref}`, `DELETE /tokens/{ref}` |

Every request sends `X-Error-Format: problem-details`; the parser also understands the legacy `exception` envelope.

## Authentication

- Context identifiers: `Nip`, `InternalId`, `NipVatUe`, `PeppolId`, validated with the patterns of `authv2.xsd`.
- Certificate flow: the `AuthTokenRequest` (namespace `http://ksef.mf.gov.pl/auth/token/2.0`) is validated
  against a local copy of `authv2.xsd`, then signed with an enveloped XAdES-BES signature
  (exclusive C14N, SHA-256 digests, RSA-SHA256 or ECDSA-SHA256 as `R||S`, `SigningCertificate` with
  `IssuerSerial`, `SigningTime`). Enveloped and enveloping signatures are accepted by KSeF; detached are not.
- Token flow: `RSA-OAEP` with SHA-256 and MGF1(SHA-256) over `"{token}|{timestampMs}"` using the key whose
  usage is `KsefTokenEncryption`; `publicKeyId` of the used key is sent.
- Authentication is asynchronous (status 100 until the certificate was checked via OCSP/CRL); the SDK polls
  with a bounded policy. Redeeming tokens is single-use, so that call is never retried on network failures.
- Access tokens are refreshed shortly before `validUntil`; if the refresh token is rejected, the SDK
  authenticates again.

## Encryption

- Session key: random 256-bit AES key and 128-bit IV per session, AES-256-CBC with PKCS#7 padding.
- The key is wrapped with RSA-OAEP (SHA-256/MGF1) using the key with usage `SymmetricKeyEncryption`.
- Public keys are cached for one hour and re-fetched when none is currently valid (key rotation).
- `invoiceHash`/`invoiceSize` describe the plaintext XML, `encryptedInvoiceHash`/`encryptedInvoiceSize` the ciphertext.

## Invoices

- FA(3), schema `1-0E`, form code `FA (3)`, namespace `http://crd.gov.pl/wzor/2025/06/25/13775/`.
  The official XSDs are bundled (only the `schemaLocation` URLs were made relative) and every generated or
  supplied document is validated against them before sending.
- KSeF requirements enforced locally: UTF-8 without BOM, no processing instructions, no DOCTYPE, forbidden
  Unicode ranges rejected, 1 MB/3 MB size limits (3 MB hard limit checked locally), at most 10,000 lines.
- Tax computation: per rate bucket on the summed net value, rounded half away from zero to 0.01.
  Rates 23/22, 8/7 share one header field each, so they cannot be mixed on one invoice.
- NIP checksums are verified locally by default; KSeF itself only verifies them on production.

## Duplicate protection and submission recovery

KSeF rejects duplicates globally with status `440` (key: seller NIP + `RodzajFaktury` + `P_2`) for ten full
years and returns `originalKsefNumber`/`originalSessionReferenceNumber`. This is the idempotency mechanism
the API offers, and the SDK builds on it instead of inventing a client-side key:

1. ambiguous failure (transport error or 5xx while sending) -> search the session list for the document hash;
2. not found -> re-send the byte-identical document (bounded, with backoff). Worst case KSeF answers 440;
3. still inconclusive -> `SubmissionOutcomeUnknownException`.

A failing lookup is not fatal (it falls back to the re-send). 4xx answers are never treated as ambiguous.

## Readiness for download

The documented readiness signal is `permanentStorageDate` in the session invoice status: it stays empty
for a few seconds after status 200. `waitForInvoice(..., untilStored: true)` polls for it.

Additionally observed on TEST, and not part of the OpenAPI contract: `GET /invoices/ksef/{number}` answers
HTTP 406 during that window. It is mapped to `InvoiceNotAvailableException` as a safety net only.

## Known limitations

- Batch sessions (ZIP upload), offline modes, QR codes, permissions management, certificate enrollment,
  Peppol queries and collective identifiers are not implemented.
- Typed invoice model covers `VAT` and `KOR`; other kinds via `InvoiceDocument::fromXml()`.
- Concurrency: token state is per `KsefClient` instance and process.
