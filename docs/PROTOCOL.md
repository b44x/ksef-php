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
| Sessions | `POST /sessions/batch`, `POST /sessions/batch/{ref}/close`, `POST /sessions/online`, `POST /sessions/online/{ref}/invoices`, `POST /sessions/online/{ref}/close`, `GET /sessions/{ref}`, `GET /sessions/{ref}/invoices`, `GET /sessions/{ref}/invoices/{inv}`, `.../upo`, `GET /sessions/{ref}/upo/{upoRef}` |
| Invoices | `GET /invoices/ksef/{ksefNumber}`, `POST /invoices/query/metadata` |
| Certificates | `GET /certificates/limits`, `GET /certificates/enrollments/data`, `POST /certificates/enrollments`, `GET /certificates/enrollments/{ref}`, `POST /certificates/retrieve`, `POST /certificates/query`, `POST /certificates/{serial}/revoke` |
| Export / limits / logins | `POST /invoices/exports`, `GET /invoices/exports/{ref}`, `GET /limits/context`, `GET /rate-limits`, `GET /auth/sessions`, `DELETE /auth/sessions/{ref|current}` |
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

## Batch sessions

Package = one ZIP of `invoice_NNNNN.xml` files, split binary into equal parts of <= 100 MB, every part encrypted
with the same per-session AES-256-CBC key/IV. `fileHash`/`fileSize` of the archive are declared for the plaintext ZIP,
those of each part for its ciphertext. Parts are uploaded with exactly the method and headers from
`partUploadRequests`, without the access token (HTTP 201 on success). Upload retries are safe (same part, same URL).
Verified against the TEST environment.

## QR codes

KOD I: `{qr-host}/invoice/{sellerNip}/{DD-MM-YYYY}/{base64url(sha256(xml))}` (matches the official example).
KOD II: `{qr-host}/certificate/{ctxType}/{ctxValue}/{sellerNip}/{certSerialHex}/{base64url(hash)}/{base64url(signature)}`,
signed over the path without scheme: RSASSA-PSS (SHA-256, MGF1 SHA-256, 32 byte salt) or ECDSA P-256 as `R||S`.
KOD II signing is covered by tests with independently verified signatures. Offline certificates can now be enrolled
(see below), but the verification page of KSeF is a JavaScript application that cannot be queried headlessly, so the
link's acceptance was confirmed manually in a browser on TEST: the verification page reported a valid issuer certificate
for both an EC and an RSA Offline certificate.

## Invoice export

Export is asynchronous: start (with a per-export AES key wrapped for the Ministry), poll until status 200, then
download the <= 50 MB parts from pre-signed URLs (no token). Each part is verified twice (hash of the ciphertext, hash
after decryption) before being appended to the destination file. Incremental synchronisation filters by
`PermanentStorage`; when a result is truncated (10,000 invoices / 1 GB) the next export starts at the last stored date.

## Certificates

The DN of a request is dictated by `GET /certificates/enrollments/data` (derived from the authenticating certificate;
any change gets the request rejected). CSRs are PKCS#10, DER, Base64, signed with SHA-256, built with phpseclib so that
repeated attributes (several `givenName`) and OIDs such as `organizationIdentifier` are encoded exactly. Keys: EC P-256
(recommended) or RSA 2048 with the plain `rsaEncryption` OID. Verified on TEST: both key types are accepted and the
issued Authentication certificates log in successfully.

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

- Offline invoicing modes (KOD I/II links are provided, offline submission flow is not), advanced permissions (authorisations, indirect, subunits, EU entities), 
  Peppol queries and collective identifiers are not implemented.
- Typed invoice model covers `VAT` and `KOR`; other kinds via `InvoiceDocument::fromXml()`.
- Concurrency: token state is per `KsefClient` instance and process.
