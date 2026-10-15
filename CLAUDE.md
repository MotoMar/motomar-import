# motomar-import

Import cenników opon od dostawców. Następca `importProducts` z motomar-php, na
produkcji od 2026-05-05. Interfejs webowy w czterech krokach: wgranie pliku,
mapowanie modeli, przypisanie sezonów, wykonanie.

Produkcja: `/home/users/deploy/apps/prod/microservices-php/tire-import`
(`ssh motomar-deploy`). Lokalnie: `https://motomar-import.test`.

## Uruchamianie

**`php` w PATH to 8.3**, bo `php@8.3` jest podlinkowane na sztywno pod legacy.
Ten projekt wymaga 8.5:

```bash
/opt/homebrew/opt/php@8.5/bin/php vendor/bin/pest
/opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpstan analyse --memory-limit=1G
```

Composer leży jako `composer.phar` obok aplikacji — lokalnie i na produkcji,
poza repo (jak w motomar-allegro-php). Aktualnie **2.10.2**, suma SHA-256
`5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027`. Nowy plik
bierz z `getcomposer.org/download/<wersja>/composer.phar` i **sprawdź sumę**
z pliku `.sha256sum` obok, zanim go gdziekolwiek położysz.

Na produkcji: `php8.5 composer.phar install --no-dev`. Bez `--no-dev` wciągnie
Pesta i PHPStana, których tam nie ma po co trzymać.

PHPStan chodzi na **poziomie 9 bez baseline'u**. Jeśli kusi Cię dopisanie
baseline'u, przeczytaj komentarz w `phpstan.neon` — był i został usunięty.

`phpstan/medoo.stub` opisuje sygnatury Medoo, bo biblioteka przyjmuje opcjonalny
`$join` na drugiej pozycji i rozstrzyga w locie, czym jest przekazany argument.
Wywołanie z joinem zostanie zgłoszone jako błąd — wtedy rozszerz stub, nie
rozluźniaj typu w kodzie.

## Skąd się bierze nazwa produktu

Jedna ścieżka, bez wyjątków:

```
kolumna `inne` w CSV  (12. kolumna, separator @, tokeny po ;)
  → tires.other
  → TireParametersBuilder + tires_dictionary
  → tires_classified_parameters.parameters  (JSON: kind => lista kodów)
  → SuffixExtractor → NameGenerator
  → products.name, products.better_slug
```

**Żaden sufiks w nazwie nie bierze się z `all_markers`, `ex_*` ani z kolumny
`reinforcement`.** Jakość nazw to dokładnie jakość klasyfikacji.

Z tego samego JSON-a czyta sklep. `oponylux.pl` buduje filtr „Typ motocykla"
joinując `tires_dictionary` po `MEMBER OF (JSON_EXTRACT(parameters, '$.purpose'))`
i grupując po kolumnach `value`/`slug` — zapytanie w
`motomar-shared/lib/motomar_shared/queries/motorcycle_tires_queries.ex`.

Kolejność operacji w imporcie ma znaczenie: **klasyfikację odświeżamy przed
wygenerowaniem nazwy**. Odwrotnie daje starą nazwę bez żadnego sygnału.

Poza importem nazwy przelicza `bin/regenerateNames.php` (`--tire`, `--tread`,
`--producer`, `--all`; `--reclassify` najpierw przelicza klasyfikację w pamięci,
więc dry-run pokazuje to, co zapis). Bez `--apply` niczego nie zapisuje.
Zapis zmienia `better_slug`, a sklep szuka produktu dokładnie po `slug` albo
`better_slug` — stary adres przestaje działać. Kopia starych wartości trafia do
`storage/logs/regenerate-names-*.jsonl`.

Nazwa jest tak dobra jak wymiary w tabelach słownikowych: opona 25146 ma
w `tires_width` cały rozmiar `4.10H19`, a w `tires_construction` `- 19`, więc
generator składa „4.10H19 - 19". Na `motomar_dev` (2026-10-08) `--all` dawał
dwie takie nazwy (25146, 93312) — przed `--all --apply` popraw dane, nie generator.

## Import z linii poleceń

`bin/import.php PLIK.csv […]` robi to, co operator klikający „dalej” we
wszystkich krokach kreatora (AKN-768): bieżnik o tej samej nazwie albo nowy
model, sezon z kolumny `sezon`, opcje kroku 4 z `ImportRunner::DEFAULT_OPTIONS`
(z tej samej stałej czyta formularz). Zapis idzie przez `ImportRunner`, wspólny
z `ExecuteController` — zmiana przebiegu importu w jednym miejscu dotyczy obu.
Bez `--apply` tylko plan. Plik, przy którym kreator czekałby na człowieka
(sezon nierozstrzygnięty, dwa bieżniki o tej nazwie, producent spoza bazy),
jest odrzucany; sezon można podać `--season="Producent|Model=Zimowa"`.
W `import_history` zapisuje się jako `cli`.

## Niezmienniki pilnowane testami

`VehicleTypeClassificationOrder` musi zaczynać się listą z
`VehicleTypeSuffixOrder`, co do kolejności. To dwa osobne pliki; rozjazd
przestawiłby nazwy produktów po cichu.

Przebudowa klasyfikacji **nie kasuje rodzajów, których nie umie wyliczyć** —
`preserveUnclassifiableKinds()`. Rodzaj obecny w słowniku, a nieobecny
w kolejności żadnego typu pojazdu, o mało nie skasował filtra sklepu.

**Import zapisuje cały plik albo nic** (`ImportTransaction`). Każdy wiersz idzie
pod savepointem, a błąd kończący transakcję (zakleszczenie 1213, zerwane
połączenie) przerywa cały import jako `ImportAborted`. Kod na ścieżce importu,
który łapie wyjątek i jedzie dalej, **musi** najpierw wołać
`ImportTransaction::rethrowIfLost($e)` — połknięte zakleszczenie zostawiło
2026-10-08 84 produkty bez opon (AKN-689). Po zakleszczeniu `inTransaction()`
wciąż zwraca true, więc rozpoznaje się je po kodzie błędu, nie po nim.

**Zmiana nazwy bieżnika nie rusza `tires_treads.slug`.** OponyLux szuka modelu
po slugu (`/model/:producer_tread`, `motomar-oponylux/.../tires_controller.ex:27`)
i nie ma przekierowań — nowy slug to martwe zaindeksowane adresy. Zmienia się
nazwa i nazwy produktów wszystkich opon bieżnika (`TreadRenamer`, AKN-690).

## Baza lokalnie

```
motomar_dev              kopia robocza, tu wolno pisać
motomar_prod_06082026    kopia produkcyjna z 6 sierpnia, do porównań
```

`bin/verifyNames.php --database=motomar_prod_06082026` porównuje wyliczone nazwy
i slugi z tym, co w bazie. Czyta tylko. Na kopii z 06.08 dawał zero różnic na
118 983 oponach — jeśli zacznie dawać inne liczby, coś się zmieniło w łańcuchu.

**Na produkcji nic nie zapisujemy z laptopa.** Odczyty przez `ssh motomar`
(MySQL) albo `ssh motomar-deploy` (komendy aplikacji).

## Pułapki tego środowiska

`grep` w PATH to **ugrep** i potrafi dać fałszywy negatyw — nie znalazł linii,
którą `/usr/bin/grep` pokazuje. Gdy wynik ma rozstrzygać o liczbach albo o tym,
czego **nie ma** w zbiorze, używaj `/usr/bin/grep` albo porównania w awk/SQL.

**Klient mysql w terminalu psuje polskie znaki** — pokazuje `ty?` zamiast `tył`.
Dane są poprawnym UTF-8; sprawdzaj przez PDO z `charset=utf8mb4`, zanim uznasz
kolumnę za uszkodzoną.

**Próbki losowo, nie po id.** `tires.id` idzie blokami po modelu i dostawcy, więc
`LIMIT N` po `id` opisuje jeden model, nie populację. Pierwsze 20 opon dało 70%
błędów, losowe 25 — 20%.

**Worktree z dowiązanym `vendor` testuje kod z głównego checkoutu.** Autoloader
Composera trzyma ścieżki do `src` względem prawdziwego położenia `vendor`, więc
symlink do `motomar-import/vendor` każe Pestowi ładować tamte klasy. Worktree
dostaje własną kopię i `php composer.phar dump-autoload` (AKN-759: 11 testów
padło na starym parserze, choć zmiana była w worktree).

## Cenniki dostawców

Przerabiamy cenniki producentów (xlsx) na CSV, które przyjmuje krok 1 importu.
Pobrane pliki i robocze CSV leżą w `tmp/` (w `.gitignore`), bo to cudze dane
handlowe. Ta sekcja to zasady, format docelowy i to, co wiemy
o każdym cenniku.

Źródło: `sites.google.com/latexopony.eu/cenniki-dla-sklepow/marki-europejskie`,
linki `drive.google.com/uc?export=download&id=…` — pobieramy `curl -L -OJ`,
nazwa pliku przychodzi w nagłówku. Stan z 2026-10-02: 28 plików.

### Format docelowy

18 kolumn i opcjonalna 19. `sezon`, separator `@`, nagłówek opcjonalny, UTF-8.
Układ pochodzi z `config/app.php` (`csv_columns`, `csv_optional_columns`),
parser to `src/Domain/Csv/CsvParser.php`:

```
numkat1@numkat2@ean@id@producent@rodzaj@bieznik@rozmiar@rozmiar2@indeksy@indeksy2@inne@opor@mokre@halas@fale@eprel@netto[@sezon]
```

Plik z 18 kolumnami czyta się jak przed AKN-759 — z tego formatu korzystają też
dostawcy.

Kontrakty, które parser egzekwuje albo na których polega import:

| kolumna | zapis | dlaczego |
|---|---|---|
| `rodzaj` | skrót z `vehicle_type_shortcuts`: `O` osobowe, `D` dostawcze, `T` terenowe/4x4, `C` ciężarowe, `M` moto… | nieznany skrót daje typ 0 |
| `rozmiar` | `205/55R16`, **bez spacji** | `SizeParser` zaczyna od `^\d+/\d+[A-Z]+\d+`; `185/60 R14` nie przejdzie |
| `indeksy` | `91V`, `104/102T` | `SizeParser::parseIndices`; zera wiodące (`096`) usuwamy |
| `inne` | kody z `tires_dictionary`, rozdzielone `;` | patrz niżej |
| `opor`, `mokre`, `fale` | jedna litera | `hasCompleteLabel()` liczy długość `opor.mokre.halas` = 4 |
| `halas` | same cyfry, `72` | — |
| `eprel` | sam numer | w wielu cennikach jest tylko w URL-u `…/qr/<numer>` |
| `netto` | puste | ceny katalogowej nigdzie nie pokazujemy; puste = `update_price` pomija wiersz, nowa opona dostaje `price` 0 |
| `sezon` | nazwa z `tires_seasons`: `Letnia`, `Zimowa`, `Całoroczna`; puste albo brak kolumny = brak podpowiedzi | krok 3 zaznacza dla nowego modelu sezon, który podaje najwięcej jego wierszy; remis albo nieznana nazwa nie zaznacza nic, rozbieżność pokazuje liczby. Sezon to cecha bieżnika — przy istniejącym modelu plik nic nie zmienia |

Opony C (dostawcze) dostają `C` w `inne`, nie w rozmiarze — tak leżą istniejące
w bazie (`other = C;3PMSF;M+S`, rozmiar `195/80R15`).

### Zasady

**1. Żadnych duplikatów opon.** Tożsamością jest EAN; import szuka najpierw po
EAN, potem po `numkat1` + producent (`ImportProcessor`). Duplikaty biorą się
z samych cenników, zanim dotkniemy bazy:

- **Pliki się nakładają.** Dwa pliki Nokian mają te same 557 EAN-ów i identyczną
  treść na 28 kolumnach (`(1)` ma jedną kolumnę więcej). Vredestein 01.06.2025
  i 01.05.2026 dzielą 651 EAN-ów, Hankook całoroczne v1 (01.01) i v2 (28.07) —
  236, `2026_NX_AS_` i Nexen 01.01.2026 — 141. **Bierzemy najnowszy plik
  marki**, starszy tylko wtedy, gdy ma pozycje, których nowy nie ma — i wtedy
  świadomie.
- **Jeden plik, kilka cenników.** Plik wielomarkowy rozbijamy na **osobny CSV
  dla każdej marki**: GCO → Continental, Uniroyal, Barum; GGY_COOPER → Goodyear,
  Cooper, Fulda, Dębica; GBR → Bridgestone, Firestone. W `GCO_…` arkusz
  „Formularz zbiorczy zamówienia" (3 920 EAN) to suma arkuszy CONTINENTAL
  (2 747) + UNIROYAL (648) + BARUM (525) — czytamy arkusze marek, zbiorczego nie.
- **Jedna pozycja, kilka EAN-ów.** Kumho ma kolumny EAN osobno dla Korei, Chin
  i Wietnamu, a „Master EAN" — każdy EAN to osobna opona (sekcja „Kraj produkcji"). Falken trzyma `336735` i `336735TH` jako osobne
  wiersze tego samego rozmiaru i bieżnika — inny kraj, **inna etykieta** (D/A
  kontra C/A) i inny EAN (`4250427423821` / `4250427439150`). To osobne opony:
  każda idzie osobnym wierszem, a **kraj produkcji dopisujemy do `inne`** obok
  oznaczeń (`XL;Turcja`). Scalić je to podać kupującemu cudzą etykietę.

**2. Żadnych duplikatów bieżników.** Bieżnik w CSV mapuje się w kroku 2 importu
na `tires_treads`. Nowy bieżnik zakładamy tylko, gdy nie ma go w bazie pod
żadną pisownią. Baza **już** ma 135 grup bieżników, które po złożeniu nazwy
(małe litery, bez spacji i znaków) są tym samym — `Bravuris 3 HM` (id 5833)
i `Bravuris 3HM` (6236), `Exedra G 703` i `Exedra G703`. Złożenie bywa też
fałszywym alarmem: `R168` i `R168+` to dwa różne bieżniki. `tread_code` jest
wypełniony w 7 z 4 025 bieżników tych marek, więc na nim nie oprzemy dopasowania.
Cenniki często dają kod bieżnika obok nazwy (Hankook `K127E`, Yokohama `V905`,
Cooper `CDISCOSTT`, Goodyear `EFFIGRICO2`) — to dobry klucz na przyszłość.

**3. Dodatkowe oznaczenia.** Słownikiem jest `tires_dictionary` (kolumna
`code`); oznaczenia konkretnej opony zapisują się do
`tires_classified_parameters.parameters`. Tokeny w `inne` muszą więc być kodami
ze słownika, bo tylko takie `TireParametersBuilder` klasyfikuje, a z wyniku
powstaje nazwa produktu i filtry sklepu (sekcja „Skąd się bierze nazwa
produktu").
Token spoza słownika nie wywala importu — po prostu znika z nazwy. Przyczepność
na lodzie, kolce i sporo technologii nie ma kodu w słowniku; zanim je wpiszemy,
trzeba dopisać kod, a to decyzja (Linear, projekt motomar-import).

**4. Etykieta: EPREL wygrywa z cennikiem.** Liczy się, czy numer w ogóle coś
zwraca. Jeśli zwraca, a nie wiemy nic o nowszej wersji zgłoszenia, to zwrócone
dane i tak mają pierwszeństwo przed cennikiem; jeśli znamy nowszą wersję —
ona. Warunek: numer należy do tej opony (marka, rozmiar, indeksy, GTIN = EAN,
jeśli jest). Zmierzone na Austone
(2026-10-02): rozjazd klas w 19 z 52 rejestracji w wersji 2 i w 4 ze 106
w wersji 1 — cenniki niosą etykietę z pierwszego zgłoszenia. Numer, który
wskazuje inny rozmiar albo obcą markę (Austone z numerami Fortune), nie jest
„starszą wersją", tylko złym numerem.

### Kraj produkcji

Kraj idzie do `inne` obok oznaczeń (`XL;M+S;Turcja`) i klasyfikuje się jako
rodzaj `country`; do nazwy produktu nie trafia. Kody w `tires_dictionary` są
**po polsku**. `DictionaryMatcher` porównuje cały token z `code` bez względu na
wielkość liter — `HUNGARY` nie zostanie rozpoznane i po cichu zniknie
(sprawdzone: `XL;HUNGARY` → `{"reinforcement":["XL"]}`). Konwerter **musi**
przepisać kraj na kod ze słownika.

**Jeden EAN, dwa kraje** (Nokian `CN/THA`, Vredestein `HUNGARY / INDIA`) —
jedna opona, cennik nie mówi, gdzie zrobiona. Zapisujemy ukośnikiem
`Chiny/Tajlandia`, w kolejności z cennika. To osobny kod w słowniku, bo `inne`
dzieli się tylko po `;` — `Chiny;Tajlandia` dałoby dwa kraje, a nie
„jeden z dwóch".

**Różne EAN-y = różne produkty**, każdy ze swoim krajem w osobnym wierszu.
Dotyczy Kumho (kolumny kodu i EAN-u osobno dla Korei, Chin i Wietnamu; w 117
z 248 wierszy arkusza Winter dwa różne EAN-y w jednym wierszu) i Falkena
(`336735` / `336735TH`).

2026-10-02 dopisaliśmy do słownika 11 krajów i 5 par (kraje 14 → 30), na
`motomar_dev` i na produkcji (tam `tires_dictionary.id` 2562–2577).

| w cenniku | kod | skąd |
|---|---|---|
| `Germany` | `Niemcy` | Dunlop |
| `France` | `Francja` | Dunlop |
| `Slovenia` | `Słowenia` | Dunlop |
| `Luxembourg` | `Luksemburg` | Dunlop |
| `Poland` | `Polska` | Dunlop |
| `Serbia` | `Serbia` | Dunlop, Cooper |
| `Turkey` | `Turcja` | Dunlop, Falken |
| `Thailand`, `THA` | `Tajlandia` | Dunlop, Falken, Yokohama, Nokian |
| `Japan`, `JPN` | `Japonia` | Dunlop, Falken, Yokohama |
| `Indonesia` | `Indonezja` | Dunlop, Falken |
| `China`, `CN`, `CHA` | `Chiny` | Dunlop, Nexen, Cooper, Kumho, Nokian, Yokohama¹ |
| `Korea` | `Korea` | Nexen, Kumho |
| `Vietnam`, `VIETNAM`, `VN` | `Wietnam` | Kumho, Vredestein, Nokian |
| `Europe` | `Europa` | Nexen |
| `HUNGARY` | `Węgry` | Vredestein |
| `INDIA`, `IND` | `Indie` | Vredestein, Yokohama² |
| `NETHERLANDS` | `Holandia` | Vredestein |
| `PHI` | `Filipiny` | Yokohama |
| `RO` | `Rumunia` | Nokian |
| `FI` | `Finlandia` | Nokian |
| `KH` | `Kambodża` | Nokian |
| `US` | `USA` | Cooper |
| `Mexico` | `Meksyk` | Cooper |
| `CN/THA` | `Chiny/Tajlandia` | Nokian |
| `CN/KH` | `Chiny/Kambodża` | Nokian |
| `CN/VN` | `Chiny/Wietnam` | Nokian |
| `FI/RO` | `Finlandia/Rumunia` | Nokian |
| `HUNGARY / INDIA` | `Węgry/Indie` | Vredestein |

Nowa pisownia albo nowy kraj: dopisujemy wiersz tutaj i kod do słownika, nie
zgadujemy w konwerterze. Para spoza tabeli — nowy kod `A/B` w słowniku.

¹ `CHA` u Yokohamy (9 pozycji, głównie ADVAN Sport V107 BMW) to założenie —
plik nie ma legendy, Yokohama ma zakłady w Suzhou i Hangzhou.
² `IND` u Yokohamy to Indie, nie Indonezja: na liście zakładów
(`y-yokohama.com/global/profile/location/overseas/`, sprawdzone 2026-10-02)
w Indonezji jest tylko sprzedaż opon, w Indiach „Yokohama India Pvt. Ltd.
(Production & Sales)", osobówki, Bahadurgarh. 19 rozmiarów BluEarth-Es ES32.

### GCO (Continental, Uniroyal, Barum)

Przerobione 2026-10-08, konwerter w `tmp/research/gco/` (gitignored).

- **Pełniejszym źródłem oznaczeń jest `Opis`**, nie kolumny: `255/50R19 103W ML
  ContiSportContact 5 SUV SSR MOE`. Przed bieżnikiem stoją rozmiar
  (prefiks `HL`/`LT`/`T`, `ZR`, sufiks `C`/`CP`), indeksy, `XL`/`RF`, `FR`/`ML`; za
  nim `SUV`, homologacje, `SSR`, `ContiSeal`, `ContiSilent` i `8PR`. Kolumna `Inne`
  bywa mniej dokładna (`MO` przy `MO-S` w opisie).
- **Legenda pliku** (arkusz „Legenda”): `+` = VW (w opisie `(+)`, kod `VW`),
  `R` = Renault (kod `RE`, bo w słowniku `R` to tylko indeks prędkości),
  `J LR` = Jaguar & Land Rover (`JLR`), `i` = NIO, `I*` = BMW Gran Coupe.
  `BB` = Brabus (SportContact 7 Force, osobny model, continental-tires.com).
- **Bez kodu w słowniku** (giną po cichu): `CRM`, `R0`, `i`, `ZK`, `BB`, `ND1`,
  `NG0`, `I*`, `AO3`, `LRD`/`LRE`. `AL` w kolumnie FR (144 wiersze) nie ma
  w legendzie, a opis tych opon mówi `FR` — bierzemy `FR` z opisu.
- `EV` dla obu `Yes with symbol` i `Yes w/o symbol` — tak leży w bazie
  (2 470 z 2 651 dopasowanych po EAN).
- `Rodzaj`: `Osobowe` i `Dojazdowe` → `O`, `VAN` → `D`, `Osobowe/SUV` i `4x4` → `T`.
- `205R16C` (bez profilu): profil z kolumny `Rozmiar` (`205/82R16`), tak jak w bazie.
- **Kolejność w `inne` to kolejność w nazwie**, a nowa nazwa to nowy `better_slug`.
  U istniejących opon zachowujemy ich kolejność `M+S`/`3PMSF`; samo przestawienie
  zmieniało 471 nazw.
- Etykiety z cennika zgadzały się z EPREL w 3 682 numerach (`checkPricelist`).

### Ceny

Nie interesują nas — ceny katalogowej nigdzie nie pokazujemy, `netto` zostaje
puste. I tak żaden z 28 plików jej nie ma (szukane w pierwszych 25 wierszach
każdego arkusza).

### Mapa plików

Nagłówek = numer wiersza (od 0) w `openpyxl`. „EPREL" mówi, gdzie leży numer.

| plik | arkusze do czytania | nagł. | EPREL | uwagi |
|---|---|---|---|---|
| `2026_KUMHO_…_DETAL.XLSX` | Winter, All season, Summer | 8 (+9 podnagłówki) | `EPREL ID` | EAN per kraj (Korea/Chiny/Wietnam) + Master EAN; Summer ma kolumny przesunięte o 2; klasy pod „EU Label Information" w wierszu 9 |
| `2026_NX_AS_.XLSX` | NEXEN 2026Y SM AS New | 2 | `EPREL` | nakłada się z Nexen 01.01.2026 (141 EAN); kolumna `Size` to sklejony klucz `2753520102WXL` |
| `NEXEN_LATO_ALLSEASON_01.01.2026` | NEXEN 2026Y SM AS New | 18 | `EPREL` | `XL/RF`, `M+S`, `EV`, `RPB` (rant `●`); `Alpine` = `O` w 169 wierszach — zakładamy 3PMSF, niesprawdzone z EPREL-em |
| `NEXEN_ZIMA_08.07.2026_VER0909` | Winter_Operation | 13 | `EPREL` | jak wyżej, `3PMSF` osobno |
| `CENNIK_KENDA_27.02.2026` | SQL00488 | 1 | `EPREL` | 17 pozycji; `C` w kolumnie bez nagłówka obok `LI/SI` |
| `COOPER_…_01062024` | Cooper | 0 | URL `…/qr/` | **z 2024**, bez EAN (tylko „EAN Family Code"); klasy etykiety wpisane jako „Professional Off road"; zastąpiony przez GGY_COOPER 2026 |
| `Cennik GT 01.10.2025 - SUASWI` | General Tire | 8 | URL `E2 EPREL Link` | układ Conti; hałas `B (72 dB)` w jednej komórce; LI z zerem `096` |
| `DUNLOP_…_01.06.2026` | DDP PLN 02.2026 EN | 7 | URL `EPREL URL` | `Status` (phase out / new size), `End of production`; `Commercial Van Mark`, `XL`, `Snowflake`, `Icemark`, `Rim protection` |
| `FALKEN_…_01.06.2026` | DDP PLN 02.2026 EN | 6 | URL `EPREL URL` | układ Dunlopa; kody z sufiksem kraju (`336735TH`) to osobne opony |
| `GBR_…_01.06.2026` | Cennik | 11 | `EPREL code` | Bridgestone/Firestone; `RFT`, `Wzmocnienie` (XL), `Rant ochronny` TAK, `Enliten`, `B-Silent`, `Oznaczenie EV` |
| `GCO_…_01.06.2026` | CONTINENTAL, UNIROYAL, BARUM | 12 | URL `E2 EPREL Link` | **nie** „Formularz zbiorczy" (suma tamtych); `Następca`, `Dostępność do`; hałas `B (71 dB)`; oznaczenia — niżej |
| `GGY_COOPER_…_VER27082026` | PriceList | 2 | URL `EPREL` | GOODYEAR 1 954, COOPER 594, FULDA 282, DEBICA 192 (u nas `Dębica`); `Rodzaj produktów (status)` PHASED-OUT; `Dodatkowe oznaczenia`; 3PMSF/M+S jako `Y`/`N` |
| `HANKOOK_ALLSEASON_01.01.2026 (1)` | — | — | — | **zastąpiony** przez v2 z 28.07 |
| `HANKOOK_*_28072026` (lato, zima, całoroczne) | arkusze EV, formularz/zima, „do wyczerpania zapasów" | 9 (+10 podnagłówki) | URL za kolumną `Uwagi` | nagłówek w dwóch wierszach: „Parametry" rozpada się na opór/przyczepność/hałas/dB; arkusz EV i główny się nie nakładają |
| `Kopia BFG_zima_cennik_2025_` | Zima 2025 | 16 | `EPREL` | **sezon 2025**; „Poziom hałasu dB" dwa razy (klasa i dB) |
| `Kopia_YOKOHAMA_ZIMA_01042026` | YOKOHAMA Winter 2026 | 13 | `EPREL NO` | `XL/RF`, `RPB`, `Z.P.S.` (run-flat), `-` znaczy brak |
| `YOKOHAMA_LATO_ALLSEASON_V4.0_01.06.2026` | Summer + 4S 2026 | 13 | `EPREL NO` | jak zima; `Not applicable` dla opon spoza rozporządzenia |
| `MICHELIN_…_01.07.2026` | Michelin | 14 | URL `QR Eprel link` | sam Michelin, bez kolumny marki; `XL` YES/NO; hałas `73 dB`; `Phase in/out`; **na końcu arkusza wiersze legendy** („LI = Load Index") do pominięcia |
| `NOKIAN_…_01.07.2026` i `(1)` | Nokian Tyres_3_2026 | 5 | `EPREL_ID` | **identyczne** na 28 kolumnach — czytamy jeden; `3PMSF / M+S` w jednej kolumnie |
| `PIRELLI_{ALLSEASON,LATO,ZIMA}` | arkusz główny | 7 | URL `EPREL URL link` | `wzmocnienie`, `run flat`, `seal inside`, `pncs`, `elect`, `homologacja OE`; hałas `B (70db)`; słownik homologacji w osobnym arkuszu |
| `Retro_Michelin_Classic_March_2025` | Tarif_01032025 | 2 | brak | 104 opony klasyczne, sam opis i numer artykułu — bez etykiety |
| `VREDESTEIN_…_01.05.2026` | VREDESTEIN, VREDESTEIN CLASSIC | 8 / 5 | `EPREL CODE` | zastępuje plik z 01.06.2025 (651 wspólnych EAN) |
| `VREDESTEIN_…_01062025` | — | — | — | **zastąpiony**; kolumna ceny to `#REF!` |

### Sprawdzanie cennika w EPREL (`bin/eprelCheck.php`)

Każdy przerobiony cennik przechodzi przez rejestr, zanim pójdzie do importu
(zasada 4). Własne narzędzie motomar-import (AKN-760); `checkPricelist`
w motomar-data-fixer jest osobnym, niezależnym narzędziem.

```bash
php bin/eprelCheck.php [--out=RAPORT.csv] [--concurrency=6] [--cache-days=7] [--problems] CENNIK.csv
php bin/eprelExport.php [KATALOG]    # eksport dzienny, wymaga EPREL_API_KEY w .env
```

- Cennik czyta parser importu (`CsvParser` z układem z `config/app.php`) — nie
  ma drugiej kopii kolumn.
- Raport: kolumny `linia…nazwa_modelu`, wyniki `OK`, `ROZJAZD`, `BEZ_EPREL`,
  `BRAK_W_EPREL`, `BLAD_POBRANIA`, rozjazdy jako `pole: nasze → ich`. Na tym
  stoi `tools/enis/apply_eprel.py` — zmiana nazwy pola albo zapisu psuje go po
  cichu.
- Rozmiar obu stron czyta `App\Eprel\TyreSize` z napisu (metryczny, calowy
  `7.50R16`, bez profilu `185R14`, flotacyjny `31x10.50R15`), bo liczby
  w rejestracji bywają przesunięte.
- Rozstrzygnięte odpowiedzi (200 z JSON-em, 404, odmowa z kodem) lądują
  w `tmp/eprel-cache/` na 7 dni: drugi przebieg tego samego cennika nie pyta
  API wcale.

Pułapki API (sprawdzone 2026-10-14):

- **Bez nagłówka `User-Agent` CloudFront odpowiada 403 na każde żądanie**,
  a bez `Referer` ze stroną produktu API odmawia tak, jakby rejestracji nie było.
- **Ponawianie odmów kończy się blokadą IP.** Pierwsza wersja klienta bez
  `User-Agent` ponawiała gołe 403 dla 44 tys. numerów i EPREL zablokował nasz
  adres na kilkanaście minut — także zwykły `curl`. Dlatego ponawiamy tylko
  brak połączenia, 429 i 5xx, a przebieg zaczyna od 20 numerów i przerywa się,
  gdy żaden nie da rozstrzygniętej odpowiedzi.
- **Limit: 50 000 żądań na 5 minut** bez klucza; z kluczem (eksport,
  wyszukiwanie) 5 na sekundę.

### Baza produktowa Enis (`tools/enis/`)

`Cenniki/Baza produktowa.xlsx` od Enisa (157 tys. opon, 241 marek, jeden
układ kolumn dla wszystkich) → CSV importu per producent w
`Cenniki/Enis/CSV/`. Jeden przebieg (AKN-756):

```bash
tools/enis/run.sh    # eksport dzienny EPREL pobiera sam, raz dziennie (bin/eprelExport.php)
```

Skrypty to Python (3.9, `openpyxl` z site-packages użytkownika — stąd
`python3 -E`, a nie `-I`) plus dwa skrypty PHP na kodzie importu:
`validate.php` (parser kroku 1; kod wyjścia 1 przy złym wierszu) i `sim.php`
(nowe nazwy istniejących opon). Dane robocze w `tmp/enis/`. Z bazy tylko czyta
(`motomar_dev`, zmienna `DB`). Numery EPREL sprawdza `bin/eprelCheck.php`.

Kontrakty:

- **Tożsamością jest EAN, nie numer EPREL.** Nowa opona musi mieć EAN
  z poprawną cyfrą kontrolną GTIN-13; numer EPREL nie jest wymagany. Enis pisze
  `0` zamiast braku numeru (Nexen) — to brak.
- **Pomijane od razu:** EAN z naszych cenników producentów
  (`Cenniki/*/*.csv`, poza `Enis/`) — cennik producenta jest lepszym źródłem;
  marki, których nie ma w `products_producers` (78 marek, 16 tys. opon — czekają
  na decyzję); EAN, który w bazie należy do innego producenta. Inna pisownia
  tej samej marki idzie przez `ALIASES` w `convert.py` (`Point S` → `Point-S`;
  potwierdzone wspólnymi EAN-ami).
- **Istniejąca opona** (po EAN albo po `numkat1` + producent, jak w
  `ImportProcessor`) zachowuje swoje `other` i bieżnik, dostaje tylko dopiski;
  oznaczenia rodzaju, który już ma (rant, run-flat, uszczelnienie, wyciszenie,
  wzmocnienie), nie dostaje drugi raz. Rant, run-flat, uszczelnienie
  i wyciszenie idą kodem, którego marka używa w bazie.
- **EV** tylko u marek, u których baza na wspólnych EAN-ach się z Enisem
  zgadza (≥ 10 i ≥ 4:1) — „EV-ready” to nie opona EV.
- **Bieżnik** to kolumna `Bieżnik` Enisa, dopasowana do bazy w kolejności:
  bieżnik istniejącej opony → bieżnik, na którym leżą opony o wspólnych EAN-ach
  → ta sama nazwa (też po złożeniu) → ten sam kod wzoru (`Z-401`) → nowy.
  Z nazwy wypadają oznaczenia, które baza trzyma w `other` (run-flat, napisy na
  boku, `XL`) i idą do `inne`; `SUV`, `VAN`, `4x4` zostają, bo baza ma je jako
  osobne bieżniki. Nowe bieżniki z najbliższym istniejącym są w
  `Enis - nowe bieżniki.csv` — w większości to nowe generacje
  (`Advantage 2`), nie inna pisownia, więc nic nie jest sklejane automatycznie.
- **EPREL wygrywa** (zasada 4) tylko na numerze tej opony, i to rozstrzyga
  się **zanim** cokolwiek z niego weźmiemy: numer, którego rejestr nie zna,
  albo rejestracja z innym rozmiarem, indeksem nośności, indeksem prędkości
  lub GTIN-em, jest usuwana i nie daje wierszowi nic — ani etykiety, ani
  indeksu (Continental dostawał etykietę Baruma 205/65R16C, WestLake zmieniał
  106T na 106H). Opona zostaje. Dopiero na potwierdzonym numerze: etykieta
  i dB z rejestru; `C` tylko gdy oznaczenie w rejestrze ma `C` przy rozmiarze;
  `XL` rozstrzyga indeks nośności. Brakujący numer bierze się z eksportu dziennego
  po `modelIdentifier` = `numkat1` (eksport nie ma EAN-ów ani nazw
  handlowych, więc działa tylko u marek, u których Enis podaje kod producenta).
- **Jeden numer EPREL bywa przy kilku EAN-ach** — u Enisa 2 916 numerów
  (AKN-758), w naszej bazie też. Przy tym samym rozmiarze, pierwszym LI, SI
  i zgodnym bieżniku (ta sama nazwa po złożeniu albo jedna zawiera drugą:
  `Z-401` / `All Season Elite Z-401`) to ta sama opona pod kilkoma EAN-ami —
  Dunlop ma stary EAN Goodyeara i nowy Sumitomo. Przy niezgodnym bieżniku
  (`Winter i*cept evo2` / `evo3`, `Cinturato P7` / `P Zero`) numer jest po
  którejś stronie cudzy, również w bazie: nowa opona, która jest jedyna ze
  swoim bieżnikiem przy tym numerze, traci go razem z etykietą i poprawkami
  z rejestru, a sama zostaje. Rozmiar przy wspólnym numerze porównujemy
  tolerancyjnie, bo baza bywa niepełna (pusty SI, `195R14` bez profilu). Takie
  przypadki są w `Enis - wspólny EPREL do przejrzenia.csv` — część to ta sama
  opona pod dwiema nazwami (Kumho `Solus HS51` / `Ecsta HS51`), czego żaden
  automat nie rozstrzygnie.
- **Żadnych duplikatów opon** — `apply_eprel.py`, na końcu. Nowa opona
  wypada, gdy jej numer EPREL ma już opona producenta (w bazie albo wcześniej
  w pliku) z tym samym rozmiarem, pierwszym LI, SI i zgodnym bieżnikiem, albo
  gdy ten sam bieżnik, rozmiar, indeksy, homologacje i obecność
  run-flat/uszczelnienia/wyciszenia ma opona w bazie albo wcześniej w pliku —
  chyba że obie mają **różne** numery EPREL (dwie rejestracje to dwie opony).
  Indeks nośności porównujemy w całości (`104/102` spotyka tylko `104/102`,
  tak go trzyma `tires.tire_li`), a w bazie liczą się też opony **bez EAN-u**
  (Accelera 6526 ma pusty EAN, a w `ref` EAN, który Enis podaje pod innym
  numkatem — import nie znalazłby jej ani tak, ani tak). Wzmocnienie i rant są
  poza porównaniem: pierwsze wynika z indeksu nośności, drugi baza zapisuje na
  cztery sposoby (FR, FSL, MFS, FP). 2026-10-14 wypadło 7 946 opon.

Kontrakty pilnują testy (bez zależności poza Pythonem):
`python3 -E -m unittest discover -s tools/enis -p 'test_*.py'`.

Pułapki:

- **API EPREL po numerze ma limit 50 000 żądań na 5 minut.** Dwa przebiegi
  po 44 tys. numerów jeden po drugim dały 14 200 błędów pobrania zamiast
  ~1 000. Cache `bin/eprelCheck.php` to rozwiązuje — powtórny przebieg tego
  samego dnia pyta tylko o numery, których nie ma w cache'u.
- `Enis - ryzyka.csv` zakłada od nowa `convert.py`, kolejne kroki dopisują.
  Kroku ze środka nie uruchamiaj osobno, bo raport się zdubluje — cały `run.sh`.
- `publish.py` czyści `Cenniki/Enis/CSV/` przed zapisem: to wyłącznie jego wyjście.
  Gdy wynik jest pusty albo niepełny, kończy się błędem i poprzedniego nie rusza.
- `mysql -B` zamienia `\n` na `\\n`, ale `\r` przepuszcza, a Python łamie na nim
  linię — bieżnik 6027 to `Surpax SP5 \r\n(duplikat)`. Zrzut w `run.sh`
  spłaszcza nazwę bieżnika; nowa kolumna tekstowa w zrzucie potrzebuje tego samego.
- `sim.php` symuluje tylko opony dopasowane po EAN; opona dopasowana po
  `numkat1` zmieni nazwę bez śladu w `zmiany nazw.tsv`.

### Czego jeszcze nie wiemy

- **Jak dojść do nowszej wersji zgłoszenia EPREL.** Do tego czasu obowiązuje
  to, co zwraca numer (zasada 4). API po numerze i eksport dzienny mają
  `lastVersion: false` na wszystkich 158 rejestracjach Austone, także tych
  z `versionNumber` 2 i 3, więc samo to pole niczego nie rozstrzyga.
- **Nazwy marek** w cennikach (`GENERAL TIRE`, `DEBICA`) nie zawsze równają się
  naszym w `products_producers` (`General`, `Dębica`) — `producent` w CSV musi
  być naszą nazwą. `producerByName()` porównuje w `utf8mb3_polish_ci`: wielkość
  liter nie gra roli, ale `DEBICA` ≠ `Dębica`, a `GENERAL TIRE` ≠ `General`
  (sprawdzone zapytaniem na `motomar_dev`).

## Sąsiednie repo

`motomar-php` (`src/ProductName`) ma **kopie** dziewięciu klas domenowych.
Repo jest legacy, zadania `populateTiresParameters` i `regenerateProductsNames`
są **deprecated**. Od 2026-08-11 kopie się różnią: tutejsza
`VehicleTypeClassificationOrder` zna `purpose` i `wheel_position` dla typów
7–10, tamta nie. Nie synchronizuj tego z powrotem bez decyzji.

Narzędzia do przeliczeń hurtem powstają w `motomar-data-fixer`, nie tutaj i nie
w motomar-php.

## Gdzie są decyzje

Otwarte pytania i pomiary: projekt **motomar-import** w Linear (zespół `AKN`).
Zanim zaproponujesz zmianę w transformacji `other`/`all_markers` albo w słowniku,
sprawdź, czy nie jest tam już opisana jako niejasność czekająca na decyzję.
Notatki sprzed migracji (2026-08-24) zostały na stronie **motomar-import** w Anytype.
