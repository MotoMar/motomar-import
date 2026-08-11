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

## Niezmienniki pilnowane testami

`VehicleTypeClassificationOrder` musi zaczynać się listą z
`VehicleTypeSuffixOrder`, co do kolejności. To dwa osobne pliki; rozjazd
przestawiłby nazwy produktów po cichu.

Przebudowa klasyfikacji **nie kasuje rodzajów, których nie umie wyliczyć** —
`preserveUnclassifiableKinds()`. Rodzaj obecny w słowniku, a nieobecny
w kolejności żadnego typu pojazdu, o mało nie skasował filtra sklepu.

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

## Sąsiednie repo

`motomar-php` (`src/ProductName`) ma **kopie** dziewięciu klas domenowych.
Repo jest legacy, zadania `populateTiresParameters` i `regenerateProductsNames`
są **deprecated**. Od 2026-08-11 kopie się różnią: tutejsza
`VehicleTypeClassificationOrder` zna `purpose` i `wheel_position` dla typów
7–10, tamta nie. Nie synchronizuj tego z powrotem bez decyzji.

Narzędzia do przeliczeń hurtem powstają w `motomar-data-fixer`, nie tutaj i nie
w motomar-php.

## Gdzie są decyzje

Otwarte pytania i pomiary: strona **motomar-import** w Anytype. Zanim
zaproponujesz zmianę w transformacji `other`/`all_markers` albo w słowniku,
sprawdź, czy nie jest tam już opisana jako niejasność czekająca na decyzję.
