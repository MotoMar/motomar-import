# Motomar Import

## Opis projektu

Motomar Import to nowoczesny system importu danych produktów opon do bazy danych e-commerce. Jest to moduł napisany w PHP, zaprojektowany jako zamiennik dla starszego systemu `importProducts`. System obsługuje import z plików CSV/XML, parsowanie parametrów opon, klasyfikację danych oraz integrację z cenami katalogowymi.

Projekt jest częścią większego ekosystemu motomar-php, obsługującego sklepy internetowe z oponami i akumulatorami (np. abmotopl, akumulatory24com).

## Funkcjonalności

- **Import produktów opon**: Automatyczne tworzenie rekordów w tabelach `products`, `tires`, `tires_classified_parameters`, `tires_parameters` itp.
- **Parsowanie danych**: Obsługa rozmiarów opon (szerokość, profil, konstrukcja), indeksów obciążenia i prędkości, markerów (XL, RF, FR).
- **Klasyfikacja parametrów**: Użycie `DictionaryMatcher` i `TireParametersBuilder` do automatycznej klasyfikacji parametrów opon na podstawie słowników.
- **Obsługa cen**: Import cen katalogowych z opcją synchronizacji grup cenowych. Checkbox "Ceny katalogowe" pozwala włączyć moduł `pricing` dla obliczania cen na podstawie danych zewnętrznych.
- **Zarządzanie producentami i modelami**: Tworzenie i aktualizacja producentów, bieżników (treads) i sezonów opon.
- **Integracja z bazą danych**: Użycie biblioteki Medoo dla bezpiecznych zapytań SQL.
- **Porównanie ze starym systemem**: W przeciwieństwie do starego `importProducts`, nowy system jest bardziej modułowy, obsługuje wszystkie kluczowe tabele (w tym brakujące wcześniej `tires_classified_parameters` i `tires_dictionary`), ale jest uproszczony dla lepszej wydajności.

## Architektura

- **Język**: PHP 8+ z strict types.
- **Baza danych**: MySQL/PostgreSQL via Medoo.
- **Struktura katalogów**:
  - `src/Domain/Tire/`: Klasy biznesowe (TireRepository, DictionaryMatcher, TireParametersBuilder).
  - `src/Domain/Csv/`: Parsery dla danych CSV (TireRow).
  - `src/Domain/Comarch/`: Integracje z systemem Comarch.
  - `src/Domain/Import/`: Logika importu.
  - `templates/`: Szablony UI (np. dla checkboxa ustawień).
  - `config/`: Konfiguracja aplikacji.
- **Autoloading**: PSR-4 dla nowoczesnego kodu.
- **Zależności**: Composer (Medoo, inne).

## Wymagania

- PHP 8.0+
- Composer
- Baza danych MySQL/PostgreSQL
- Dostęp do plików CSV/XML z danymi opon

## Instalacja

1. Sklonuj repozytorium:
   ```bash
   git clone https://github.com/your-username/motomar-import.git
   cd motomar-import
   ```

2. Zainstaluj zależności:
   ```bash
   composer install
   ```

3. Skonfiguruj bazę danych w `config/app.php` (użyj zmiennych środowiskowych dla bezpieczeństwa).

4. Uruchom migracje bazy danych (jeśli potrzebne):
   ```bash
   # Przykład: php scripts/migrate.php
   ```

## Użycie

### Podstawowy import

1. Przygotuj plik danych (np. `data.csv` z kolumnami: ean, name, size, indices, price, itp.).
2. Uruchom import:
   ```bash
   php src/Controller/ExecuteController.php
   ```
   Lub użyj interfejsu webowego w `templates/step1.php` – `step4-confirm.php`.

### Ustawienia importu

- **Checkbox "Ceny katalogowe"**: W interfejsie (np. `step2.php`) zaznacz, aby włączyć obliczanie cen na podstawie modułu `pricing`. Jeśli zaznaczone, ceny są aktualizowane w `products.price_catalog_netto` i synchronizowane z `products_price_groups`.

### Przykład kodu

```php
use App\Domain\Tire\TireRepository;

$repo = new TireRepository();
$data = [
    'ean' => '1234567890123',
    'name' => 'Michelin Pilot Sport 4',
    'price' => 500.00,
    // ... inne dane
];
$productId = $repo->createTire($data);
```

### Naprawy i rozszerzenia

W porównaniu do starego systemu:
- Dodano obsługę `tires_classified_parameters` dla klasyfikacji parametrów.
- Zintegrowano `others` (XL; RF;...) i `all_markers` (XL, FR,...) do tabel `tires_parameters` i `markers`.
- Obsługa `tires_dictionary` dla tłumaczeń.
- Moduł `pricing` dla cen katalogowych (aktywny przy zaznaczonym checkboxie).

## Testowanie

Uruchom testy PHPUnit:
```bash
php vendor/bin/phpunit
```

## Przyczynianie się

1. Forkuj repozytorium.
2. Stwórz branch dla zmian.
3. Zatwierdź zmiany i stwórz pull request.

## Licencja

MIT License – zobacz `LICENSE` dla szczegółów.

## Kontakt

Dla pytań: admin@motomar.pl
