<?php
/**
 * Konfiguracja aplikacji FClass Report
 * 
 * Centralna konfiguracja dla systemu raportowania wyników zawodów strzeleckich.
 * Zawiera ustawienia bazy danych, aplikacji oraz stałe systemowe.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

// Ustawienia podstawowe PHP
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
date_default_timezone_set('Europe/Warsaw');

/**
 * Główna konfiguracja aplikacji
 * 
 * Wszystkie ustawienia są scentralizowane w tej tablicy
 * dla łatwego zarządzania i modyfikacji
 */
return [
    // === KONFIGURACJA BAZY DANYCH ===
    'db' => [
        'dsn'      => 'mysql:host=sql.laserowytrening.home.pl;port=3306;dbname=laserowytrening2;charset=utf8mb4',
        'user'     => 'laserowytrening2',
        'password' => 'laserowyf-class',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            PDO::ATTR_PERSISTENT         => false, // Dodane dla bezpieczeństwa
            PDO::ATTR_EMULATE_PREPARES   => false, // Lepsze prepared statements
        ],
    ],
    
    // === USTAWIENIA APLIKACJI ===
    'app' => [
        // Podstawowe ustawienia
        'name'                => 'FClass Report System',
        'version'             => '9.0',
        'year'                => 2025,
        'min_year'            => 2020, // Najwcześniejszy obsługiwany rok
        'max_year'            => null, // null = bieżący rok + 1
        
        // Cache i wydajność
        'cache_ttl'           => 300,   // 5 minut w sekundach
        'cache_enabled'       => true,
        'cache_prefix'        => 'fclass_v9_',
        
        // Kodowanie i lokalizacja
        'source_charset'      => 'utf8',    // 'latin2' jeśli baza używa ISO-8859-2
        'locale'              => 'pl_PL',
        'timezone'            => 'Europe/Warsaw',
        
        // Limity i ograniczenia
        'max_event_columns'   => 8,     // Zwiększono z 4 na 8
        'max_results_per_class' => 1000, // Limit wyników na klasę
        'query_timeout'       => 30,    // Timeout dla zapytań w sekundach
        
        // Bezpieczeństwo
        'allow_debug'         => false,  // Czy pokazywać debug info
        'validate_table_names' => true,  // Walidacja nazw tabel
        'sanitize_inputs'     => true,   // Sanityzacja inputów
    ],
    
    // === STAŁE SYSTEMOWE ===
    'constants' => [
        // Regex patterns dla walidacji
        'day_table_pattern'   => '/^\d{8}$/',           // YYYYMMDD
        'class_id_pattern'    => '/^[1-9]\d*$/',        // Positive integers
        'name_pattern'        => '/^[\p{L}\s\-\.\']{1,50}$/u', // Unicode names
        
        // Filtry zawodów
        'excluded_keywords'   => ['22lr', 'test'],      // Case-insensitive
        'me_detection_phrase' => 'EUROPEAN CHAMPIONSHIPS', // Case-insensitive
        
        // Domyślne wartości sortowania
        'default_sort'        => 'rank',
        'default_direction'   => 'asc',
        
        // Formaty eksportu
        'export_formats'      => ['csv', 'json'],
        'csv_delimiter'       => ',',
        'csv_enclosure'       => '"',
    ],
    
    // === MAPOWANIE KLAS ===
    'classes' => [
        '1' => 'FTR',
        '2' => 'Open', 
        '3' => 'Magnum',
        '4' => 'Semi-Auto',
        '5' => 'Semi-Auto Open',
        '6' => 'Sniper',
        '7' => 'Sniper Open',
        '8' => 'Ultra Magnum',
        // Możliwość łatwego dodawania nowych klas
    ],
    
    // === REGUŁY RANKINGU ===
    'ranking' => [
        // Kwalifikacja do rankingu rocznego
        'min_me_events'       => 1,     // Minimum startów ME z wynikiem > 0
        'min_other_events'    => 2,     // Minimum startów nie-ME z wynikiem > 0
        
        // Obliczanie wyniku rocznego
        'scoring_events'      => [
            'me_events'       => 1,     // Najlepszy wynik ME
            'other_events'    => 2,     // 2 najlepsze wyniki nie-ME
        ],
        
        // Tie-breaking rules (w kolejności stosowania)
        'tiebreakers'         => [
            'total_score',          // 1. Wynik łączny
            'me_score',             // 2. Wynik ME
            'best_other_score',     // 3. Najlepszy wynik nie-ME
            'second_best_score',    // 4. Drugi najlepszy wynik nie-ME
            'total_starts',         // 5. Liczba startów
            'lastname_firstname',   // 6. Alfabetycznie
        ],
    ],
    
    // === USTAWIENIA ZESPOŁÓW ===
    'teams' => [
        'min_members'         => 2,     // Minimum członków zespołu
        'max_members'         => 2,     // Maximum członków zespołu
        'only_me_events'      => true,  // Zespoły tylko dla ME
    ],
    
    // === KONFIGURACJA EKSPORTU ===
    'export' => [
        'csv' => [
            'bom'             => true,   // UTF-8 BOM dla Excel
            'delimiter'       => ',',
            'enclosure'       => '"',
            'escape'          => '"',
        ],
        'json' => [
            'flags'           => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            'pretty_print'    => false,
        ],
    ],
    
    // === USTAWIENIA UI ===
    'ui' => [
        'results_per_page'    => 100,   // Dla przyszłej paginacji
        'date_format'         => 'Y-m-d',
        'datetime_format'     => 'Y-m-d H:i:s',
        'number_decimals'     => 2,
        'thousand_separator'  => ' ',
        'decimal_separator'   => ',',
        
        // Bootstrap classes
        'css_framework'       => 'bootstrap5',
        'theme'               => 'default',
    ],
    
    // === LOGGING I DEBUG ===
    'logging' => [
        'enabled'             => false,
        'level'               => 'INFO',  // DEBUG, INFO, WARN, ERROR
        'file'                => null,    // null = sys_get_temp_dir()
        'max_file_size'       => 10485760, // 10MB
    ],
    
    // === BEZPIECZEŃSTWO ===
    'security' => [
        'csrf_protection'     => false,  // Dla przyszłych form
        'rate_limiting'       => false,  // Dla API
        'allowed_ips'         => [],     // Pusta tablica = wszystkie IP
        'require_auth'        => false,  // Dla przyszłej autoryzacji
    ],
];
?>