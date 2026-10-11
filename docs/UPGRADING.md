# Upgrading

Before 1.0 the API could still change between minor versions; every breaking change is listed here with the fix.
From 1.0 on, only major versions break the [public API](STABILITY.md).

## 0.11 to 0.12

No breaking changes: `ksef-doctor` and the release automation are additive.

## 0.10 to 0.11

No breaking changes: everything is additive (invoice reader, manual signing, test data API).

## 0.9 to 0.10

No breaking changes: everything is additive (token store, availability, UPO signature, QR renderer, offline deadlines).

## 0.8 to 0.9

| Before | After |
| --- | --- |
| `grantEntityPermissions($nip, $name, $permissions, $description)` | `grantEntityPermissions(EntitySubject::nip($nip, $name), $permissions, $description)` |
| `grantAuthorization($nipOrPeppolId, $type, $name, $description)` | `grantAuthorization(EntitySubject::nip($nip, $name), $type, $description)` or `EntitySubject::peppolProvider($peppolId, $name)` |

## 0.7 to 0.8

| Before | After |
| --- | --- |
| `$result['permissions']` / `['roles']` / `['providers']` / `['certificates']` / `['sessions']` | `$result->items` (a `Pagination\Page`, also iterable and countable) |
| `$result['hasMore']`, `$result['continuationToken']` | `$result->hasMore`, `$result->continuationToken` |
| `sessionInvoices()`, `failedSessionInvoices()` | `listSessionInvoices()`, `listFailedSessionInvoices()` |
| `authSessions()` | `listAuthSessions()` |
| `collectiveIdentifiers()`, `collectiveIdentifierInvoices()`, `collectiveIdentifiersOf()` | `listCollectiveIdentifiers()`, `listCollectiveIdentifierInvoices()`, `listCollectiveIdentifiersOf()` |
| `peppolProviders()` | `listPeppolProviders()` |
| `myPermissions()`, `personPermissions()`, `entityPermissions()`, `authorizations()`, `subunitAdministrators()`, `euEntityPermissions()`, `entityRoles()`, `subordinateEntities()` | the same names with a `list` prefix: `listMyPermissions()`, `listPersonPermissions()`, ... |
| `generateToken()` | `createToken()` |
| `sendBatch($invoices, 4_000, $formCode, true)` | `sendBatch($invoices, new BatchOptions(maxPartBytes: 4_000, formCode: $formCode, offline: true))`, or `BatchOptions::offline()` |
| `grantEuEntityAdministrator($admin, $vatUe, $name, $address, $description)` | `grantEuEntityAdministrator($admin, new EuEntity($vatUe, $name, $address), $description)` |
| `CertificateInfo::$status` (string), `searchCertificates(status: 'Active')` | `CertificateStatus` enum |
| `ApiException::$cause` | `getPrevious()` |

A search and replace over your code base covers most of it; static analysis finds the rest.

## 0.5 to 0.6, 0.6 to 0.7

No breaking changes.
