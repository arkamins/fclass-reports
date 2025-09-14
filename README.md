# FClass Report System v9.0

Zaawansowany system raportowania wyników zawodów strzeleckich F-Class z obsługą rankingu rocznego, zespołów oraz eksportu danych.

## 🎯 Najważniejsze zmiany w v9.0

### ✅ Poprawki sortowania
- **Domyślne sortowanie po miejscu (rosnąco)** w widoku wyników
- Sortowanie dostępne w kolumnie "Miejsce" 
- Usunięto sortowanie z kolumny "Wynik łączny"
- Poprawiona logika sortowania dla wszystkich kolumn

### 🚀 Optymalizacje wydajności
- **Nowy system cache** z TTL i integrity checking
- **Walidacja bezpieczeństwa** nazw tabel i parametrów wejściowych
- **Lazy loading** i optymalizacja zapytań SQL
- **Rate limiting** dla ochrony przed nadużyciem

### 🏗️ Refaktoryzacja architektury
- **Separation of concerns** - widoki wydzielone do osobnych plików
- **Ujednolicone API** dla wszystkich funkcjonalności
- **Rozszerzone komentarze** i dokumentacja kodu
- **Error handling** i logging błędów

### 🎨 Ulepszone UI/UX
- **Responsive design** z Bootstrap 5
- **Breadcrumb navigation** 
- **Tooltips i hover effects**
- **Loading states** i progress indicators
- **Collapsible tables** dla lepszej czytelności

## 📁 Struktura projektu

```
/
├── app/                          # Logika biznesowa
│   ├── cache.php                 # System cache z integrity checking
│   ├── classmap.php              # Mapowanie klas z rozszerzonymi funkcjami
│   ├── config.php                # Centralna konfiguracja
│   ├── db.php                    # Zarządzanie bazą danych z walidacją
│   ├── encoding.php              # Kodowanie i sanityzacja danych
│   ├── event_results.php         # Logika wyników wydarzeń
│   ├── query_ranking.php         # Logika rankingu rocznego
│   └── teams.php                 # Logika zespołów (zachowany)
├── public/                       # Pliki publiczne
│   ├── assets/
│   │   └── style.css             # Style CSS
│   ├── views/                    # Widoki aplikacji
│   │   ├── ranking_view.php      # Widok rankingu
│   │   ├── results_view.php      # Widok wyników
│   │   └── teams_view.php        # Widok zespołów (do stworzenia)
│   ├── export.php                # Eksport rankingu
│   ├── index.php                 # Główny plik aplikacji
│   ├── results_export.php        # Eksport wyników
│   └── teams_export.php          # Eksport zespołów (zachowany)
└── README.md                     # Ta dokumentacja
```

## ⚡ Szybki start

### 1. Wdrożenie plików

**Zastąp następujące pliki:**
```bash
# Podstawowe pliki konfiguracji
app/config.php
app/cache.php  
app/classmap.php
app/db.php
app/encoding.php

# Logika biznesowa  
app/event_results.php
app/query_ranking.php

# Interfejs użytkownika
public/index.php
public/export.php
public/results_export.php

# Nowe widoki (stwórz katalog views/)
public/views/results_view.php
public/views/ranking_view.php
```

### 2. Konfiguracja

**Zaktualizuj `app/config.php`:**
```php
// Sprawdź ustawienia bazy danych
'db' => [
    'dsn' => 'mysql:host=YOUR_HOST;dbname=YOUR_DB;charset=utf8mb4',
    'user' => 'YOUR_USER',
    'password' => 'YOUR_PASSWORD',
],

// Dostosuj rok i ustawienia
'app' => [
    'year' => 2025,
    'min_year' => 2020,
    'cache_ttl' => 300,
],
```

### 3. Testowanie

1. **Test podstawowy:** Odwiedź `index.php` - powinien się załadować widok wyników
2. **Test sortowania:** Kliknij na nagłówek "Miejsce" - tabela powinna się posortować
3. **Test rankingu:** Przejdź do widoku "Ranking" - sprawdź czy dane się ładują
4. **Test eksportu:** Spróbuj wyeksportować dane do CSV/JSON

## 🔧 Konfiguracja zaawansowana

### Cache
```php
'app' => [
    'cache_enabled' => true,      // Włącz/wyłącz cache
    'cache_ttl' => 300,          // TTL w sekundach (5 min)
    'cache_prefix' => 'fclass_', // Prefix dla kluczy cache
],
```

### Bezpieczeństwo
```php
'security' => [
    'validate_table_names' => true,  // Walidacja nazw tabel
    'sanitize_inputs' => true,       // Sanityzacja danych wejściowych
    'allowed_ips' => [],             // Ograniczenia IP (puste = wszystkie)
],
```

### Klasy zawodów
```php
'classes' => [
    '1' => 'FTR',
    '2' => 'Open',
    '3' => 'Magnum',
    '4' => 'Semi-Auto',
    '5' => 'Semi-Auto Open', 
    '6' => 'Sniper',
    '7' => 'Sniper Open',
    '8' => 'Ultra Magnum',        // Nowa klasa
],
```

## 📊 Funkcjonalności

### Widok wyników
- ✅ **Sortowanie po miejscu (domyślnie)**
- ✅ Sortowanie po wszystkich kolumnach wyników
- ✅ Filtrowanie po roku i dniu
- ✅ Eksport CSV/JSON
- ✅ Responsive tabele
- ✅ Hover effects i tooltips

### Ranking roczny  
- ✅ Kwalifikacja: ≥1 ME + ≥2 inne starty
- ✅ Wynik = najlepszy ME + 2 najlepsze inne
- ✅ Oznaczanie wybranych wyników
- ✅ Filtrowanie po klasach
- ✅ Statystyki uczestnictwa
- ✅ Collapsible tabele

### Zespoły ME
- ✅ Zachowana funkcjonalność
- ✅ Ranking zespołów
- ✅ Eksport danych

### System eksportu
- ✅ **CSV z UTF-8 BOM** (kompatybilność z Excel)
- ✅ **JSON z metadanymi**
- ✅ Filtrowanie po klasach
- ✅ Walidacja parametrów
- ✅ Error handling

## 🐛 Rozwiązywanie problemów

### Problemy z cache
```bash
# Wyczyść cache ręcznie
rm -rf /tmp/fclass_cache/*

# Lub przez kod PHP
cache_clear();
```

### Problemy z bazą danych
```php
// Sprawdź połączenie
$stats = getDbStats();
var_dump($stats);

// Sprawdź czy tabela istnieje  
$exists = tableExists('20250315');
```

### Błędy kodowania
```php
// Test kodowania
$test = to_utf8('test string');
$valid = validate_encoding($test, 'UTF-8');
```

### Debug mode
```php
'app' => [
    'allow_debug' => true,  // Włącz szczegółowe błędy
],
```

## 📈 Monitoring i wydajność

### Cache stats
```php
// Sprawdź statystyki cache
$stats = cache_stats();
print_r($stats);
```

### Database stats  
```php
// Sprawdź status bazy danych
$stats = getDbStats();
print_r($stats);
```

### Cleanup cache
```php
// Usuń stare wpisy cache (starsze niż 1h)
$deleted = cache_cleanup(3600);
echo "Usunięto {$deleted} plików";
```

## 🔄 Migracja z poprzedniej wersji

### Backup
```bash
# Utwórz backup przed migracją
cp -r /ścieżka/do/aplikacji /backup/fclass_$(date +%Y%m%d)
```

### Kompatybilność
- ✅ **Struktura bazy danych** - bez zmian
- ✅ **URL-e** - zachowane (backward compatibility)
- ✅ **Eksport API** - rozszerzone, zachowana kompatybilność
- ⚠️ **Cache** - nowy system, stary cache zostanie wyczyszczony

### Migracja krok po kroku
1. **Backup** istniejącej aplikacji
2. **Zastąp pliki** zgodnie z listą w sekcji "Szybki start"
3. **Zaktualizuj config.php** z nowymi opcjami
4. **Stwórz katalog** `public/views/`
5. **Test** podstawowych funkcjonalności
6. **Wyczyść stary cache** jeśli wystąpią problemy

## 🛠️ Development

### Struktura kodu
- **MVC pattern** - Models (app/), Views (public/views/), Controllers (public/)
- **Dependency injection** - przez require_once
- **Error handling** - try/catch z logowaniem
- **Caching** - wielopoziomowy cache z TTL
- **Security** - input validation, SQL injection protection

### Dodawanie nowych funkcji
1. **Model** - dodaj logikę do odpowiedniego pliku w `app/`
2. **View** - stwórz nowy plik w `public/views/`  
3. **Controller** - dodaj routing w `public/index.php`
4. **Cache** - dodaj cache dla nowych danych
5. **Tests** - przetestuj wszystkie ścieżki

### Code style
- **PSR-12** - standard formatowania
- **phpDoc** - dokumentacja wszystkich funkcji
- **Type hints** - dla wszystkich parametrów i return values
- **Error handling** - zawsze z try/catch
- **Logging** - error_log() dla wszystkich błędów

## 📞 Wsparcie

### Logi błędów
```bash
# Sprawdź logi PHP
tail -f /var/log/php_errors.log

# Sprawdź logi aplikacji  
tail -f /tmp/fclass_application.log
```

### Najpowszechniejsze problemy
1. **Błąd "Invalid table name"** - sprawdź format daty (YYYYMMDD)
2. **"Cache write error"** - sprawdź uprawnienia do /tmp
3. **"Database connection failed"** - sprawdź credentials w config.php
4. **Puste tabele** - sprawdź filtry wykluczające ('22lr', 'test')

### Wydajność
- **Cache hit ratio** powinien być >80%
- **Query time** <100ms dla większości zapytań
- **Memory usage** <50MB dla normalnych operacji

## 🔮 Roadmapa

### v9.1 (planowane)
- [ ] **Virtual scrolling** dla dużych tabel (>100 zawodników)
- [ ] **AJAX loading** bez przeładowania strony
- [ ] **Advanced filtering** z wieloma kryteriami
- [ ] **Export to XML** format

### v9.2 (planowane)  
- [ ] **User authentication** system
- [ ] **Data validation** rules w UI
- [ ] **Progressive Web App** support
- [ ] **Real-time updates** przez WebSockets

### v10.0 (przyszłość)
- [ ] **GraphQL API** endpoint
- [ ] **React/Vue frontend** SPA
- [ ] **Multi-tenant** support
- [ ] **Machine learning** predictions

---

## 🏆 Autorzy

**FClass Report Team**
- Refaktoryzacja v9.0: Claude (Anthropic)
- Oryginalny kod: Zespół FClass Report
- Wersja: 9.0
- Data: 2025

---

*Więcej informacji i wsparcie: sprawdź logi aplikacji i sekcję troubleshooting powyżej.*