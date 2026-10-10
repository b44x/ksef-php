# ksef-php

[English](README.md) · **Polski**

Niezależny od frameworka SDK dla PHP do polskiego **Krajowego Systemu e-Faktur (KSeF) API 2.0**.

Biblioteka bierze na siebie wszystko, co leży między Twoją aplikacją a KSeF: uwierzytelnianie (certyfikat XAdES
lub token KSeF), odświeżanie tokenów, szyfrowanie po stronie klienta, generowanie XML faktury FA(3) z walidacją
XSD, sesje interaktywne, asynchroniczne sprawdzanie statusów, pobieranie UPO, pobieranie i wyszukiwanie faktur.
Twój kod operuje na obiektach domenowych, a nie na endpointach, XML-u czy kryptografii.

- Działa z dowolnym klientem HTTP PSR-18 i loggerem PSR-3; nie wymaga żadnego frameworka
- Ścisłe typy, niemutowalne obiekty wartości, enumy, brak stanu globalnego
- Bezpieczna konstrukcja: wysyłka nigdy nie jest ślepo powtarzana, duplikaty wykrywa KSeF, sekrety nie trafiają do logów
- Zweryfikowana z oficjalną specyfikacją OpenAPI KSeF (v2.8.1) i sprawdzona od końca do końca na publicznym środowisku TEST

> **Status:** przed 1.0. Publiczne API jest opisane poniżej i pokryte testami, ale przed `1.0.0` może jeszcze
> ulec zmianom. Zobacz [CHANGELOG.md](CHANGELOG.md).

## Wymagania

- PHP 8.2 lub nowszy
- Rozszerzenia: `dom`, `json`, `libxml`, `mbstring`, `openssl`
- Klient HTTP PSR-18 oraz fabryki PSR-17 (np. Guzzle albo Symfony HttpClient z `nyholm/psr7`)

## Instalacja

```bash
composer require b44x/ksef-php guzzlehttp/guzzle
```

`guzzlehttp/guzzle` to tylko jeden z możliwych klientów HTTP; sam SDK zależy wyłącznie od interfejsów PSR.

## Wypróbuj w 60 sekund

```bash
git clone https://github.com/b44x/ksef-php.git && cd ksef-php && composer install
php examples/01-send-invoice.php
```

Nie potrzeba żadnego konta: przykład działa na publicznym środowisku TESTOWYM KSeF z jednorazowym podatnikiem
(przykłady używają Guzzle, zależności deweloperskiej). Trzynaście przykładów krok po kroku jest w
[examples/](examples/README.md), a drogę na produkcję opisuje [docs/GETTING-STARTED.md](docs/GETTING-STARTED.md).

## Szybki start

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use B4x\Ksef\Auth\CertificateCredentials;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Invoice\Address;
use B4x\Ksef\Invoice\Buyer;
use B4x\Ksef\Invoice\BuyerIdentifier;
use B4x\Ksef\Invoice\Invoice;
use B4x\Ksef\Invoice\InvoiceLine;
use B4x\Ksef\Invoice\Seller;
use B4x\Ksef\Invoice\VatRate;
use B4x\Ksef\KsefClient;
use B4x\Ksef\Support\Nip;

$factory = new HttpFactory();
$ksef = KsefClient::builder()
    ->environment(Environment::Test)                       // brak domyślnego: wybierz świadomie
    ->httpClient(new Client(['timeout' => 30, 'connect_timeout' => 10]), $factory, $factory)
    ->context(ContextIdentifier::nip('5265877635'))        // w czyim imieniu pracujesz
    ->credentials(CertificateCredentials::fromPemFiles('/secure/cert.pem', '/secure/key.pem'))
    ->build();

$invoice = Invoice::builder()
    ->number('FV/2026/06/001')
    ->issueDate('2026-06-01')
    ->seller(new Seller(Nip::of('5265877635'), 'Example Seller sp. z o.o.', Address::poland('ul. Prosta 1', '00-001 Warszawa')))
    ->buyer(new Buyer(BuyerIdentifier::nip(Nip::of('1234563218')), 'Sample Buyer S.A.'))
    ->addLine(InvoiceLine::of('Consulting', '10', 'h', '150.00', VatRate::Rate23))
    ->build();                                             // rzuca ValidationException z listą wszystkich problemów

$submission = $ksef->sendInvoice($invoice);                // przyjęta do przetwarzania – JESZCZE nie zatwierdzona
$result = $ksef->waitForInvoice($submission)->assertAccepted();

echo $result->ksefNumber;                                  // np. 5265877635-20260601-0100001AF629-15
$upo = $ksef->invoiceUpo($submission);                     // podpisane potwierdzenie odbioru (XML)
```

Działające, w pełni otypowane warianty są w [`examples/`](examples).

## Co trzeba wiedzieć

Przetwarzanie w KSeF jest **asynchroniczne** i SDK modeluje to uczciwie:

| Krok | Znaczenie | SDK |
| --- | --- | --- |
| Żądanie utworzone | `Invoice` zwalidowana, XML zbudowany i sprawdzony XSD | `Invoice::builder()->build()`, `InvoiceDocument` |
| Żądanie wysłane / przyjęte | KSeF zwrócił HTTP 202: *przyjęto do przetwarzania* | `InvoiceSubmission` |
| Przetworzona | status 200 i numer KSeF albo odrzucenie (kody 4xx) | `waitForInvoice()` → `SessionInvoice` |
| Zapisana | ustawione `permanentStorageDate`; dokument można pobrać | `waitForInvoice(..., untilStored: true)`, `isPermanentlyStored()` |
| UPO dostępne | podpisane potwierdzenie odbioru | `invoiceUpo()` |

## Konfiguracja

### Środowiska

`Environment::Test` (dozwolone certyfikaty samopodpisane, bez prawdziwych danych), `Environment::Demo`
(przedprodukcyjne) i `Environment::Production`. Do serwera zastępczego użyj `baseUrl()`.

### Uwierzytelnianie

KSeF wydaje krótkożyjący token dostępu (JWT) oraz token odświeżania. SDK wykonuje cały przepływ
przezroczyście przy pierwszym użyciu: *challenge → dowód tożsamości → odpytywanie statusu → odbiór tokenów*,
odświeża token dostępu przed wygaśnięciem i wraca do pełnego uwierzytelnienia, gdy token odświeżania zostanie
odrzucony. Odpowiedź `401` wywołuje dokładnie jedno ponowne uwierzytelnienie i powtórzenie żądania.

Obsługiwane są dwa rodzaje poświadczeń:

```php
// 1. Certyfikat (kwalifikowany podpis/pieczęć, certyfikat KSeF albo samopodpisany na TEST).
//    AuthTokenRequest jest podpisywany XAdES-BES (RSA >= 2048 bit lub EC P-256+).
CertificateCredentials::fromPemFiles($certPath, $keyPath, $optionalPassphrase);
CertificateCredentials::fromPkcs12(file_get_contents($p12), $password);

// 2. Token KSeF (wygenerowany w aplikacji KSeF lub przez $ksef->createToken()).
//    Wysyłany jako RSA-OAEP(SHA-256) z "token|challengeTimestampMs".
new KsefTokenCredentials(getenv('KSEF_TOKEN'));
```

Użyj `CertificateCredentials` z własnym `XadesSigner`, aby trzymać klucz prywatny w HSM lub zdalnej usłudze
podpisu. Miejsca użycia tokenu dostępu ogranicza `->allowedIps(new AllowedIps([...]))`.

> Certyfikaty kwalifikowane są na PRE/PROD weryfikowane przez KSeF przez OCSP/CRL, więc „uwierzytelnianie w
> toku” może chwilę potrwać. Czekanie jest ograniczone i konfigurowalne (`authenticationPolling()`).
> KSeF zaleca na produkcji uwierzytelnianie *certyfikatem KSeF*.

### Opcje buildera

```php
KsefClient::builder()
    ->environment(...)            // albo ->baseUrl('https://...')
    ->httpClient($client, $requestFactory, $streamFactory)
    ->context(...)->credentials(...)
    ->logger($psr3Logger)         // opcjonalnie; nigdy nie dostaje sekretów ani treści dokumentów
    ->retryPolicy(new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 0.5, maxDelaySeconds: 30.0))
    ->polling(new PollingPolicy(timeoutSeconds: 120.0))      // domyślne dla waitFor*()
    ->authenticationPolling(...)->allowedIps(...)->publicKeyProvider(...)->clock(...)->sleeper(...)
    ->build();
```

Limity czasu i weryfikacja TLS są właściwościami Twojego klienta PSR-18; ustaw rozsądne timeouty i zostaw
weryfikację certyfikatów włączoną.

## Tworzenie faktury

`Invoice` to niemutowalny agregat, zawsze poprawny. Kwoty to dokładne liczby dziesiętne (`Decimal`, w API
łańcuchy znaków) – floaty nigdy nie są przyjmowane. Pozycje wycenione netto; podatek liczony jest per stawka od
zsumowanej wartości netto i zaokrąglany half away from zero, jak przewiduje ustawa o VAT.

```php
$invoice = Invoice::builder()
    ->number('FV/2026/06/002')->issueDate('2026-06-01')->saleDate('2026-05-31')->issuePlace('Warszawa')
    ->currency('EUR')->exchangeRate('4.3210')                // waluta obca: VAT w PLN jest wyliczany
    ->seller($seller)->buyer($buyer)
    ->addLine(InvoiceLine::of('Software licence', '1', 'szt.', '1000.00', VatRate::Rate23, 'EUR'))
    ->addLine(InvoiceLine::of('Training', '2', 'h', '80.00', VatRate::Exempt, 'EUR'))
    ->annotations(new Annotations(splitPayment: true, exemptionBasis: 'Art. 43 ust. 1 pkt 29 lit. b ustawy o VAT'))
    ->payment(Payment::dueOn(new DateTimeImmutable('2026-06-15'), PaymentMethod::BankTransfer, ['PL61109010140000071219812874']))
    ->build();
```

Obsługiwane: faktury zwykłe (`VAT`) i korygujące (`KOR`), jeden sprzedawca, jeden nabywca (polski NIP, VAT UE,
zagraniczny identyfikator podatkowy lub brak), wszystkie typowe sposoby opodatkowania (23/22/8/7/5 %, warianty 0 %,
`zw`, `oo`, `np`), kody GTU, dane płatności, adnotacje i stopka. Korekty: oznacz stan pierwotny przez
`InvoiceLine::asBefore()` i dodaj pozycje po korekcie albo odwróć pozycje oryginału ujemną ilością lub ceną;
sumy stają się różnicami automatycznie.

**Inne formy faktur.** Poza FA(3) SDK obsługuje faktury zakupu od rolnika ryczałtowego FA_RR (1) z typowanym
modelem (`RrInvoice`, zobacz `examples/11-farmer-rr-invoice.php`) i przyjmuje dokumenty Peppol (faktury PEF (3) i
noty korygujące PEF_KOR (3)) jako zweryfikowany surowy XML: `InvoiceDocument::fromXml($ublXml)` rozpoznaje formę po
elemencie głównym i sprawdza ją z dołączonym schematem. Wysyłka faktur PEF jako dostawca Peppol jest sprawdzona
od końca do końca na TEST (`examples/13-peppol-invoice.php`, [docs/PEPPOL.md](docs/PEPPOL.md)), łącznie z notami
korygującymi (PEF_KOR).

**Dodatki opcjonalne** (wszystkie w `examples/12-rich-invoice.php`): `InvoiceLine::withDiscount()`, `deliveredOn()`,
`withProcedure()`, `withExcise()`; uwagi `addInfo()`, `addWarehouseDocument()`; `additionalSettlement()` (obciążenia i
odliczenia); `Payment::partlyPaid()`, skonto i inne formy płatności; `terms()` (umowy, zamówienia, numery partii,
warunki dostawy); procedury marży i znaczniki podmiotów powiązanych w `Annotations`; oraz ustrukturyzowany
`attachment()`. KSeF przyjmuje załączniki tylko w sesjach wsadowych i tylko od podatników, którzy wcześniej wyrazili
zgodę (`attachmentStatus()`); SDK odmawia wysłania ich w sesji interaktywnej.

Dodatkowe podmioty (`Podmiot3`: odbiorca, płatnik, faktor, ...) dodajesz przez `addThirdParty(ThirdParty::of(ThirdPartyRole::Recipient, ...))`.

Model typowany pokrywa każdą część FA(3), także wewnątrzwspólnotową dostawę nowych środków transportu
(`Annotations::$newTransport`). Dane sprzedawcy/nabywcy przed korektą (`Podmiot1K`/`Podmiot2K`) trafiają do
`Correction`, transporty i walutę umowną obsługuje `TransactionTerms`, numery EORI, znaczniki JST/grupy VAT oraz
adresy korespondencyjne – `Seller` i `Buyer`.

Rodzaje szczególne (jak wypełniane jest każde pole i co potwierdzić z księgowym: [docs/ADVANCE-INVOICES.md](docs/ADVANCE-INVOICES.md)):

```php
// Faktura zaliczkowa (ZAL): podatek wyliczany z kwoty brutto wpłaty; pozycje opisują zamówienie.
Invoice::builder()->...->advance(new AdvancePayment(Money::pln('1230.00'), VatRate::Rate23, $paidOn))
    ->addLine(InvoiceLine::of('Custom software', '1', 'szt.', '5000.00', VatRate::Rate23))->build();

// Faktura końcowa (ROZ): pozycje niosą pełną sprzedaż; P_13/P_14/P_15 pokazują to, co zostało po zaliczkach.
Invoice::builder()->...->settlement(new Settlement([AdvanceInvoiceReference::ksef($advanceKsefNumber)], Money::pln('1230.00')))
    ->addLine(...)->build();

// Korekta faktury zaliczkowej (KOR_ZAL): połącz correction() i advance(); kwota zaliczki to
// ZMIANA wpłaty (ujemna, gdy maleje), pozycje to zamówienie przed (asBefore) i po.
Invoice::builder()->...->correction($correction)->advance(new AdvancePayment(Money::pln('-615.00'), VatRate::Rate23, $paidOn))
    ->addLine($orderLine->asBefore())->addLine($newOrderLine)->build();

// Korekta faktury końcowej (KOR_ROZ): połącz correction() i settlement(); pozycje przed/po.
Invoice::builder()->...->correction($correction)->settlement($settlement)->addLine($line->asBefore())->addLine($newLine)->build();

// Korekta zbiorcza za okres (art. 106j ust. 3): bez pozycji, różnice per stawka.
Invoice::builder()->...->correction(new Correction($corrected, period: '2026-04-01 - 2026-06-30', amounts: [CorrectionAmount::of(VatRate::Rate23, '-100.00', '-23.00')]))->build();

// Faktura uproszczona (UPR): do 450 PLN / 100 EUR, nabywca identyfikowany NIP-em.
Invoice::builder()->...->simplified()->addLine(...)->build();
```

**Furtka na surowy XML.** Dowolny dokument FA(3) można wysłać po weryfikacji:

```php
$document = InvoiceDocument::fromXml($xml);   // rozmiar, UTF-8/BOM, DOCTYPE, PI, przestrzeń nazw i XSD
$ksef->sendInvoice($document);
```

## Wysyłanie faktur

```php
$submission = $ksef->sendInvoice($invoice);                  // jedna faktura, własna krótka sesja

$session = $ksef->openOnlineSession();                       // wiele faktur, jedna sesja (<= 12 h, <= 10 000 faktur)
$a = $session->send($invoiceA);
$b = $session->send($invoiceB);
$session->close();                                           // uruchamia generowanie zbiorczego UPO
$status = $session->waitUntilFinished();                     // SessionStatus wraz ze stronami UPO
```

Każda faktura jest szyfrowana kluczem AES-256-CBC per sesja, owiniętym kluczem RSA Ministerstwa (OAEP, SHA-256).
Skróty i rozmiary jawnego tekstu oraz szyfrogramu są liczone za Ciebie.

### Sesje wsadowe

Przy dużych wolumenach wyślij paczkę: SDK waliduje każdą fakturę, buduje ZIP (plik tymczasowy, nigdy w całości w
pamięci), dzieli go na części do 100 MB (maks. 50 części, 10 000 faktur), szyfruje każdą część, wysyła je na
podpisane adresy zwrócone przez KSeF i zamyka sesję. Wymaga `ext-zip`.

```php
$batch = $ksef->sendBatch($invoices);                         // iterable z Invoice | InvoiceDocument | łańcuch XML
$status = $ksef->waitForSession($batch->sessionReference);    // SessionStatus (liczniki zbiorcze, strony UPO)
$page = $ksef->listSessionInvoices($batch->sessionReference);     // per faktura: ksefNumber, status, invoiceHash
// $batch->invoiceHashes pozwala przypisać wyniki do własnych dokumentów.
```

### Uprawnienia

```php
$ksef->grantPersonPermissions(PersonSubject::byPesel($pesel, 'Anna', 'Nowak'), [Permission::InvoiceRead, Permission::InvoiceWrite], 'accountant');
$ksef->grantEntityPermissions(Nip::of('5265877635'), 'Partner sp. z o.o.', ['InvoiceRead' => true]);   // może delegować: true
foreach ($ksef->listPersonPermissions(grantedByMe: true)->items as $grant) { /* $grant->id, ->scope, ->holder */ }
$ksef->revokePermission($grant->id);
```

Nadanie i odebranie uprawnień jest w KSeF asynchroniczne; te wywołania czekają na operację i rzucają
`PermissionOperationException` (z kodem statusu KSeF), gdy zostanie odrzucona. Dostępne także: `listMyPermissions()`,
`listEntityPermissions()`.

Szczególne ustalenia mają własne wywołania, wszystkie asynchroniczne w KSeF i awaitowane przez SDK:

```php
// Uprawnienia na poziomie podmiotu: samofakturowanie, RR, przedstawiciel podatkowy, Peppol
$ksef->grantAuthorization(Nip::of('5265877635'), EntityAuthorizationType::SelfInvoicing, 'Partner sp. z o.o.', 'self-billing');
$ksef->listAuthorizations(AuthorizationDirection::Granted);   // ...::Received
$ksef->revokeAuthorization($authorization->id);           // nie revokePermission(): inny endpoint

// Biuro rachunkowe: osoba pracuje w kontekstach Twoich klientów
$ksef->grantIndirectPermissions($person, [EntityPermissionType::InvoiceRead], 'staff', IndirectTarget::allPartners());

// Jednostki podrzędne (JST, grupy VAT) i podmioty UE
$ksef->grantSubunitAdministrator($person, SubunitContext::internalId('5265877635-12345'), 'branch admin');
$ksef->grantEuEntityAdministrator(EuEntitySubject::person(PersonSubject::byFingerprint(...)), new EuEntity('5265877635-DE123456789', 'Muster GmbH', 'Berlin'), 'admin');
$ksef->grantEuEntityRepresentative(EuEntitySubject::entity($fingerprint, 'Seal GmbH', 'Berlin'), [EuEntityPermissionType::InvoiceWrite], 'rep');

// Odczyt: listSubunitAdministrators(), listEuEntityPermissions(), listEntityRoles(), listSubordinateEntities(), attachmentStatus()
```

### Eksport, limity i logowania

```php
// Odszyfrowany ZIP z {ksefNumber}.xml + _metadata.json; kolejne okna czasu przez continueFrom (PermanentStorage).
$package = $ksef->exportInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, null, '/tmp/export.zip');
if ($package->isTruncated) { $from = $package->continueFrom; /* eksportuj ponownie */ }

$ksef->contextLimits();   // maks. liczba faktur / rozmiary per typ sesji
$ksef->rateLimits();      // ['invoiceSend' => RateLimit(perSecond, perMinute, perHour), ...]
$ksef->listAuthSessions();    // aktywne logowania; $ksef->revokeAuthSession($ref) albo revokeAuthSession() dla bieżącego
```

### Identyfikatory zbiorcze i Peppol

```php
// Jeden numer referencyjny płatności dla wielu faktur tego samego sprzedawcy
$id = $ksef->createCollectiveIdentifier([new CollectiveInvoice($ksefNumber1, Money::pln('123.00'), 'May'), new CollectiveInvoice($ksefNumber2)]);
$page = $ksef->listCollectiveIdentifiers($from, $to);                      // stronicowane; $page->continuationToken dla następnej strony
$ksef->listCollectiveIdentifierInvoices([$id]);                            // faktury (dane płatności tylko dla uprawnionych)
$ksef->listCollectiveIdentifiersOf($ksefNumber1);                          // do jakich identyfikatorów należy faktura

$ksef->listPeppolProviders();                                             // zarejestrowani dostawcy usług Peppol
$ksef->subjectLimits();                                               // limity rejestracji certyfikatów / certyfikatów podatnika
```

### Certyfikaty KSeF

KSeF wydaje własne certyfikaty (typ `Authentication` do logowania, typ `Offline` do podpisywania linków KOD II).
`requestCertificate()` przeprowadza cały proces: sprawdzenie limitu, pobranie danych podmiotu, lokalne wygenerowanie
klucza i CSR (domyślnie EC P-256, opcjonalnie RSA 2048), wysłanie, oczekiwanie, odbiór. **Klucz prywatny istnieje
tylko w zwróconym obiekcie: zapisz go w menedżerze sekretów.** Sesja musi być uwierzytelniona *podpisem*
(`CertificateCredentials`); KSeF odmawia rejestracji z sesji tokenowych.

```php
$cert = $ksef->requestCertificate('billing service', CertificateType::Authentication);   // KeyType::EcP256
$credentials = $cert->toCredentials();               // od teraz loguj się nim (bez opóźnień OCSP/CRL)

$offline = $ksef->requestCertificate('offline qr', CertificateType::Offline);
$signer = $offline->toOfflineCertificate();          // dla VerificationLinks::certificateUrl()

$ksef->certificateLimits();  $ksef->searchCertificates(CertificateType::Offline);  $ksef->revokeCertificate($serial);
```

### Kody QR

Fakturowanie offline (wystawienie bez KSeF, dostarczenie później, korekta techniczna) ma własny przewodnik: [docs/OFFLINE.md](docs/OFFLINE.md).

`VerificationLinks` buduje linki do kodów QR na wizualizacji faktury (SDK nie rysuje obrazka; podaj link dowolnej
bibliotece ISO/IEC 18004, np. `bacon/bacon-qr-code`):

```php
$links = new VerificationLinks(Environment::Production);
$url = $links->invoiceUrl($sellerNip, $issueDate, $document);            // KOD I, każda faktura
$label = $links->label($ksefNumber);                                      // numer KSeF albo "OFFLINE"
$url2 = $links->certificateUrl($context, $sellerNip, $document->hash(),   // KOD II, tylko faktury offline
    new OfflineCertificate($certPem, $keyPem));                           // certyfikat KSeF typu "Offline"
```

### Niezawodność: timeouty, ponowienia i duplikaty

Timeout lub 5xx podczas wysyłki faktury nigdy nie dowodzi, że KSeF jej nie otrzymał. Dlatego SDK uzgadnia stan
automatycznie zamiast zgadywać (`SubmissionRecoveryPolicy`, domyślnie włączone):

1. szuka w sesji skrótu dokumentu; jeśli KSeF go ma, zwraca wysyłkę z
   `$submission->recovered === true` i niczego nie wysyła ponownie;
2. w przeciwnym razie wysyła *identyczny* dokument jeszcze raz (domyślnie do 2 razy, odstępy 1 s i 2 s). To nie
   może utworzyć drugiej faktury: KSeF wykrywa duplikaty globalnie po *NIP sprzedawcy + rodzaj faktury +
   numer faktury* i odpowiada `440` z `originalKsefNumber`;
3. jeśli to nadal nie rozstrzyga, rzucany jest `SubmissionOutcomeUnknownException` (z numerem sesji i skrótem;
   `findSubmission()` może to rozstrzygnąć później).

Odmowa (4xx) na dowolnym etapie jest ostateczna i propaguje się bez zmian. Dostrajanie lub wyłączenie:
`->submissionRecovery(new SubmissionRecoveryPolicy(maxResends: 3))` / `SubmissionRecoveryPolicy::disabled()`.
Dla odzyskanych wysyłek użyj `assertStored()` (akceptuje też duplikat 440: dokument jest w KSeF).

Pozostałe wywołania: dla żądań modyfikujących ponawiane jest tylko HTTP 429 (`Retry-After` honorowany do limitu);
wywołania tylko do odczytu są ponawiane także przy błędach sieci i 500/502/503/504. Odpytywanie jest zawsze
ograniczone (`PollingPolicy`); `PollingTimeoutException` znaczy „jeszcze nie wiadomo”, a nie „niepowodzenie”.

## Sprawdzanie statusu

```php
$invoice = $ksef->invoiceStatus($submission);          // jedno żądanie
$invoice = $ksef->waitForInvoice($submission);         // odpytuje do stanu końcowego; może to być odrzucenie

$invoice->status->isAccepted();                        // kod 200
$invoice->status->isRejected();                        // kod >= 400
$invoice->status->isDuplicate();                       // 440 -> originalKsefNumber()
$invoice->assertAccepted();                            // rzuca InvoiceRejectedException z wyjaśnieniem KSeF
$invoice->assertStored();                              // jak assertAccepted(), ale duplikat 440 się liczy (już w KSeF)

// Przyjętą fakturę można pobrać, gdy ustawione jest `permanentStorageDate`:
$ksef->waitForInvoice($submission, null, untilStored: true);
```

## Pobieranie UPO, faktur i wyszukiwanie

```php
$upo = $ksef->invoiceUpo($submission);                 // skrót zapowiedziany przez KSeF jest weryfikowany ($upo->verifyHash())
$status = $ksef->sessionStatus($sessionRef);           // $status->upoPages -> $ksef->sessionUpo($sessionRef, $page->referenceNumber)

$invoice = $ksef->downloadInvoice($ksefNumber);
//   Najpierw poczekaj przez waitForInvoice(..., untilStored: true). Jako zabezpieczenie, pobranie, które nadal
//   zwraca HTTP 406 (obserwowane przez kilka sekund po przyjęciu), rzuca InvoiceNotAvailableException albo jest
//   awaitowane, gdy podasz PollingPolicy jako drugi argument.

$page = $ksef->searchInvoices(InvoiceSubjectType::Buyer, InvoiceDateType::PermanentStorage, $from, $to);
```

## Obsługa błędów

Wszystkie wyjątki dziedziczą po `B4x\Ksef\Exception\KsefException`.

| Wyjątek | Znaczenie | Typowa reakcja |
| --- | --- | --- |
| `ValidationException` (`SerializationException`) | Lokalna walidacja nie przeszła; **nic nie wysłano**; `->violations` zawiera wszystkie problemy | Popraw dane |
| `ApiException` + `AuthenticationException` (401), `AuthorizationException` (403, `->reasonCode`), `RateLimitException` (429, `->retryAfterSeconds`), `ServerException` (5xx) | KSeF odpowiedział błędem; `->httpStatus`, `->ksefCode()`, `->errors`, `->traceId` | Zależnie od statusu; zgłoś `traceId` wsparciu KSeF |
| `InvoiceRejectedException` | Przetwarzanie zakończyło się statusem błędu (`->status`) | Popraw fakturę; dla 440 zobacz `originalKsefNumber()` |
| `SubmissionOutcomeUnknownException` | Błąd sieci/5xx podczas wysyłki, a automatyczne uzgodnienie nie rozstrzygnęło | Później `findSubmission()` albo wyślij ten sam dokument ponownie |
| `TransportException` / `MalformedResponseException` | Brak użytecznej odpowiedzi HTTP / naruszenie kontraktu | Powtórz operacje odczytu później |
| `PollingTimeoutException` | Budżet czasu wyczerpany; operacja może się jeszcze zakończyć | Odpytaj ponownie później |
| `InvoiceNotAvailableException` | Przyjęta faktura jeszcze nie zapisana (HTTP 406) | Poczekaj i powtórz |
| `SigningException`, `EncryptionException`, `ConfigurationException`, `SessionException` | Lokalne problemy z kryptografią, konfiguracją lub stanem sesji | Popraw konfigurację |

Komunikaty i dane wyjątków nigdy nie zawierają tokenów, kluczy prywatnych ani treści żądań.

## Bezpieczeństwo

- Sekrety są wstrzykiwane, nigdy zaszyte w kodzie. Wczytuj tokeny i klucze z magazynu sekretów lub środowiska.
  Obiekty przechowujące sekrety ukrywają wartości przed `var_dump()`/logami i są oznaczone `#[\SensitiveParameter]`.
- Tokeny żyją tylko w pamięci. SDK nigdy nie zapisuje poświadczeń na dysk ani do logów.
- Klucze prywatne: RSA < 2048 bitów są odrzucane; klucz musi pasować do certyfikatu.
- XML: dokumenty z `DOCTYPE` są odrzucane (brak XXE/rozwijania encji), dostęp sieciowy w libxml jest wyłączony,
  a podpisane/serializowane dokumenty powstają przez DOM (bez sklejania łańcuchów).
- TLS: skonfiguruj weryfikację certyfikatów w kliencie PSR-18; SDK odrzuca bazowe URL-e inne niż HTTPS
  (poza localhost) oraz linki pobierania inne niż HTTPS.
- Odpowiedzi są czytane z limitem rozmiaru (32 MiB dla API, 64 MiB dla części), więc wrogi serwer nie wyczerpie pamięci.
- Kryptografia korzysta z OpenSSL i phpseclib; żaden algorytm nie jest zaimplementowany w tej bibliotece.
- `Environment::Test` akceptuje certyfikaty samopodpisane; nigdy nie używaj tam danych produkcyjnych.

Luki zgłaszaj zgodnie z opisem w [SECURITY.md](SECURITY.md).

## Integracja z frameworkami

Rdzeń nie zależy od żadnego frameworka. Podłącz go w swoim kontenerze, na przykład:

```php
// Laravel service provider / definicja usługi Symfony: zarejestruj KsefClient jako współdzieloną usługę
// zbudowaną z klienta PSR-18, loggera PSR-3 i sekretu z konfiguracji Twojego frameworka.
```

Dedykowane mostki mogą żyć w osobnych pakietach; punktami integracji są interfejsy PSR.

## Architektura

```
KsefClient (fasada)
 ├─ Auth\            Authenticator, AccessTokenProvider, poświadczenia, AuthApi
 ├─ Session\         OnlineSession (szyfrowanie, wysyłka, odpytywanie, zamknięcie)
 ├─ Api\             SessionApi, InvoiceApi, TokenApi  – cienkie, typowane opakowania endpointów
 ├─ Invoice\         Model domenowy, walidator, Fa3Serializer, InvoiceDocument (sprawdzony XSD)
 ├─ Crypto\          Dostawca kluczy publicznych, RSA-OAEP, AES-256-CBC, skróty
 ├─ Signing\         Interfejs XadesSigner + implementacja OpenSSL
 ├─ Http\            Transport (PSR-18), polityka ponowień, mapowanie błędów, AuthorizedClient
 ├─ Polling\         PollingPolicy, Poller
 └─ Status\, Exception\, Support\ (Decimal, Nip, KsefNumber), Xml\
```

Decyzje projektowe i fakty o protokole, na których opiera się implementacja, opisują
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) i [docs/PROTOCOL.md](docs/PROTOCOL.md).

## Testy

```bash
composer install
composer test        # unit + integration (deterministyczne atrapy PSR-18, bez sieci)
composer analyse     # PHPStan, poziom max + reguły strict
composer lint        # php-cs-fixer (composer fix poprawia)
composer check       # wszystko powyższe plus composer validate
composer bench       # mikro-benchmarki (czas i pamięć), bez sieci
```

**Testy na żywo** (opcjonalne) przeprowadzają pełny cykl na publicznym środowisku TEST KSeF: tworzą jednorazowego
podatnika przez API `/testdata` (tylko TEST), uwierzytelniają się certyfikatem samopodpisanym i świeżo
wygenerowanym tokenem KSeF, wysyłają fakturę, czekają na przyjęcie, pobierają UPO, pobierają fakturę i sprawdzają
wykrywanie duplikatów. Nie są potrzebne żadne poświadczenia:

```bash
KSEF_LIVE=1 composer test:live
```

## Wydajność

`composer bench` (`tools/bench.php`) mierzy części zależne od CPU i pamięci. Przykładowy wynik (jeden rdzeń
sandboxa, PHP 8.3; wartości zależą od maszyny, porównuj uruchomienia na tej samej):

| Scenariusz | Czas |
| --- | ---: |
| zbudowanie i walidacja faktury, 1000 pozycji | ~4 ms |
| serializacja + walidacja XSD, 1 pozycja / 100 / 1000 | ~5 / ~7 / ~32 ms |
| AES-256-CBC, 10 MiB: szyfrowanie / odszyfrowanie | ~15 / ~5 ms |
| paczka: serializacja + ZIP 100 faktur | ~0,5 s |
| paczka: serializacja + ZIP 1000 faktur | ~6 s (pamięć szczytowa ~2 MiB) |

Koszt przy wysyłce wsadowej to głównie walidacja XSD (kilka ms na fakturę); pamięć rośnie z liczbą faktur o
kilka bajtów na skrót, a ZIP i szyfrowanie działają strumieniowo, po jednej części naraz.

## Stabilność

Publiczne API podlega wersjonowaniu semantycznemu; co jest publiczne (a co `@internal`) opisuje [docs/STABILITY.md](docs/STABILITY.md).

## Współtworzenie

Zobacz [CONTRIBUTING.md](CONTRIBUTING.md). Przed otwarciem pull requesta uruchom `composer install && composer check`.

## Licencja

MIT. Zobacz [LICENSE](LICENSE). Dołączone schematy XSD są publikowane przez polskie Ministerstwo Finansów
(zobacz `resources/schemas`).
