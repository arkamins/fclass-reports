<?php
/**
 * Zarządzanie wynikami wydarzeń strzeleckich
 * 
 * Obsługuje pobieranie, przetwarzanie i prezentację wyników zawodów.
 * Implementuje bezpieczne sortowanie, ranking oraz cache dla wydajności.
 * Obsługuje również wyniki Long Range (dystans d4).
 * 
 * @author FClass Report Team
 * @version 10.0
 * @since 2025
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/encoding.php';

/**
 * Normalizuje i waliduje nazwę tabeli dziennej
 * 
 * Sprawdza czy nazwa tabeli jest w formacie YYYYMMDD oraz czy jest bezpieczna.
 * 
 * @param string $day Nazwa tabeli (format YYYYMMDD)
 * @return string|null Znormalizowana nazwa lub null jeśli niepoprawna
 */
function er_normalize_day_table(string $day): ?string {
    $config = require __DIR__ . '/config.php';
    
    // Sprawdź format YYYYMMDD
    if (!preg_match($config['constants']['day_table_pattern'], $day)) {
        return null;
    }
    
    // Dodatkowa walidacja daty
    $year = (int)substr($day, 0, 4);
    $month = (int)substr($day, 4, 2);
    $dayNum = (int)substr($day, 6, 2);
    
    // Sprawdź czy data jest poprawna
    if (!checkdate($month, $dayNum, $year)) {
        return null;
    }
    
    // Sprawdź czy rok jest w dozwolonym zakresie
    $minYear = $config['app']['min_year'];
    $maxYear = $config['app']['max_year'] ?? ((int)date('Y') + 1);
    
    if ($year < $minYear || $year > $maxYear) {
        return null;
    }
    
    // Waliduj bezpieczeństwo nazwy tabeli
    if (!validateTableName($day)) {
        return null;
    }
    
    return $day;
}

/**
 * Pobiera maksymalny rok z dostępnych danych
 * 
 * Znajduje najnowszy rok dla którego są dostępne dane zawodów.
 * Używa cache dla optymalizacji wydajności.
 * 
 * @param bool $useCache Czy używać cache
 * @return int Maksymalny dostępny rok
 */
function er_fetch_max_year(bool $useCache = true): int {
    $cacheKey = 'max_year';
    
    if ($useCache) {
        $cached = cache_get($cacheKey, 3600); // 1 godzina cache
        if ($cached !== null) {
            return (int)$cached;
        }
    }
    
    try {
        $maxYear = getMaxYear($useCache);
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $maxYear);
        }
        
        return $maxYear;
        
    } catch (Exception $e) {
        error_log("Error fetching max year: " . $e->getMessage());
        
        // Fallback na bieżący rok
        return (int)date('Y');
    }
}

/**
 * Pobiera listę wydarzeń dla danego roku wraz z Long Range
 * 
 * Zwraca wszystkie zawody w roku spełniające kryteria filtrowania.
 * Automatycznie filtruje wykluczone słowa kluczowe.
 * Dodaje wirtualne wydarzenia Long Range (d4).
 * 
 * @param int $year Rok do wyszukania
 * @param bool $useCache Czy używać cache
 * @return array Lista wydarzeń [['day' => string, 'opis' => string], ...]
 */
function er_fetch_events_for_year(int $year, bool $useCache = true): array {
    $cacheKey = "events_year_{$year}_with_lr";
    
    if ($useCache) {
        $cached = cache_get($cacheKey, 1800); // 30 minut cache
        if ($cached !== null) {
            return $cached;
        }
    }
    
    try {
        $config = require __DIR__ . '/config.php';
        $excludedKeywords = $config['constants']['excluded_keywords'];
        $mePhrase = $config['constants']['me_detection_phrase'];
        
        $pdo = db();
        $min = (int)($year . '0000');
        $max = (int)($year . '9999');
        
        // Buduj warunki WHERE dla wykluczonych słów kluczowych
        $excludeConditions = [];
        $params = [':min' => $min, ':max' => $max];
        
        foreach ($excludedKeywords as $index => $keyword) {
            $paramKey = ":exclude_{$index}";
            $excludeConditions[] = "LOWER(opis) NOT LIKE {$paramKey}";
            $params[$paramKey] = '%' . strtolower($keyword) . '%';
        }
        
        $excludeClause = !empty($excludeConditions) ? 'AND ' . implode(' AND ', $excludeConditions) : '';
        
        $sql = "
            SELECT data, opis
            FROM zawody
            WHERE data BETWEEN :min AND :max
              AND opis IS NOT NULL
              AND TRIM(opis) <> ''
              {$excludeClause}
            ORDER BY data ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        
        $events = [];
        foreach ($rows as $row) {
            $day = (string)$row['data'];
            $normalizedDay = er_normalize_day_table($day);
            
            if ($normalizedDay === null) {
                continue;
            }
            
            // Sprawdź czy tabela rzeczywiście istnieje
            if (!tableExists($normalizedDay)) {
                continue;
            }
            
            $opis = clean_for_db($row['opis']);
            
            // Dodaj standardowe wydarzenie
            $events[] = [
                'day' => $normalizedDay,
                'opis' => $opis,
                'is_long_range' => false,
                'real_day' => $normalizedDay,
            ];
            
            // Sprawdź czy to ME
            $isME = (stripos($opis, $mePhrase) !== false);
            $distanceSuffix = $isME ? '1100m' : '1000y';
            
            // Dodaj wirtualne wydarzenie Long Range (data +1 dzień)
            $virtualDay = date('Ymd', strtotime($normalizedDay . ' +1 day'));
            $events[] = [
                'day' => $virtualDay,
                'real_day' => $normalizedDay,  // Rzeczywista tabela z danymi
                'opis' => $opis . ' - ' . $distanceSuffix,
                'is_long_range' => true,
                'distance_label' => $distanceSuffix,
                'distance_field' => 'd4'
            ];
        }
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $events);
        }
        
        return $events;
        
    } catch (Exception $e) {
        error_log("Error fetching events for year {$year}: " . $e->getMessage());
        return [];
    }
}

/**
 * Pobiera informacje o konkretnym wydarzeniu
 * 
 * @param string $day Dzień wydarzenia
 * @param array|null $events Lista wydarzeń (opcjonalna)
 * @return array|null Informacje o wydarzeniu
 */
function er_get_event_info(string $day, ?array $events = null): ?array {
    if ($events === null) {
        $year = (int)substr($day, 0, 4);
        $events = er_fetch_events_for_year($year);
    }
    
    foreach ($events as $event) {
        if ($event['day'] === $day) {
            return $event;
        }
    }
    
    return null;
}

/**
 * Pobiera surowe wyniki dla danego wydarzenia
 * 
 * Ładuje wszystkie wyniki z tabeli wydarzenia z podstawową walidacją danych.
 * 
 * @param string $table Nazwa tabeli wydarzenia
 * @return array Surowe wyniki z bazy danych
 */
function er_fetch_results_for_event(string $table): array {
    // Waliduj nazwę tabeli
    if (!validateTableName($table)) {
        error_log("Invalid table name: {$table}");
        return [];
    }
    
    // Sprawdź czy tabela istnieje
    if (!tableExists($table)) {
        error_log("Table does not exist: {$table}");
        return [];
    }
    
    try {
        $config = require __DIR__ . '/config.php';
        $pdo = db();
        
        $sql = "
            SELECT
                class,
                COALESCE(TRIM(fname),'') AS fname,
                COALESCE(TRIM(lname),'') AS lname,
                COALESCE(res_d1,0) AS res_d1,
                COALESCE(res_d2,0) AS res_d2,
                COALESCE(res_d3,0) AS res_d3,
                COALESCE(res_d4,0) AS res_d4,
                COALESCE(x_d1,0)   AS x_d1,
                COALESCE(x_d2,0)   AS x_d2,
                COALESCE(x_d3,0)   AS x_d3,
                COALESCE(x_d4,0)   AS x_d4,
                COALESCE(moa_d1,NULL) AS moa_d1,
                COALESCE(moa_d2,NULL) AS moa_d2,
                COALESCE(moa_d3,NULL) AS moa_d3,
                COALESCE(moa_d4,NULL) AS moa_d4
            FROM `{$table}`
            ORDER BY class, lname, fname
        ";
        
        // Dodaj limit dla bezpieczeństwa
        if (isset($config['app']['max_results_per_class'])) {
            $limit = $config['app']['max_results_per_class'] * 10; // Bezpieczny buffer
            $sql .= " LIMIT {$limit}";
        }
        
        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll();
        
        // Oczyść dane
        return array_map('clean_for_db', $results);
        
    } catch (Exception $e) {
        error_log("Error fetching results for table {$table}: " . $e->getMessage());
        return [];
    }
}

/**
 * Buduje tabele wyników dla wydarzenia z obsługą sortowania
 * 
 * Główna funkcja przetwarzająca wyniki wydarzenia. Obsługuje agregację,
 * ranking, sortowanie oraz walidację danych. Obsługuje również Long Range.
 * 
 * @param string $day Dzień wydarzenia (YYYYMMDD)
 * @param string|null $sort Kolumna sortowania
 * @param string $direction Kierunek sortowania ('asc' lub 'desc')
 * @param bool $useCache Czy używać cache
 * @return array Struktura ['meta' => array, 'tables' => array]
 */
function er_build_event_tables(
    string $day, 
    ?string $sort = null, 
    string $direction = 'asc',
    bool $useCache = true
): array {
    $config = require __DIR__ . '/config.php';
    
    // Pobierz informacje o wydarzeniu
    $year = (int)substr($day, 0, 4);
    $events = er_fetch_events_for_year($year);
    $eventInfo = er_get_event_info($day, $events);
    
    // Jeśli nie znaleziono wydarzenia, zwróć puste dane
    if ($eventInfo === null) {
        return ['meta' => ['day' => $day, 'opis' => ''], 'tables' => []];
    }
    
    // Sprawdź czy to Long Range
    $isLongRange = $eventInfo['is_long_range'] ?? false;
    $realDay = $eventInfo['real_day'] ?? $day;
    $distanceLabel = $eventInfo['distance_label'] ?? null;
    
    // Normalizuj parametry
    $normalizedDay = er_normalize_day_table($realDay);
    if ($normalizedDay === null) {
        return ['meta' => ['day' => $day, 'opis' => ''], 'tables' => []];
    }
    
    $sort = er_validate_sort_column($sort, $isLongRange);
    $direction = er_validate_sort_direction($direction);
    
    // Cache key
    $cacheType = $isLongRange ? 'lr' : 'std';
    $cacheKey = "event_tables_{$day}_{$cacheType}_{$sort}_{$direction}";
    
    if ($useCache) {
        $cached = cache_get($cacheKey, 900); // 15 minut cache
        if ($cached !== null) {
            return $cached;
        }
    }
    
    try {
        // Pobierz surowe wyniki z rzeczywistej tabeli
        $rawResults = er_fetch_results_for_event($normalizedDay);
        if (empty($rawResults)) {
            return ['meta' => ['day' => $day, 'opis' => $eventInfo['opis']], 'tables' => []];
        }
        
        // Agreguj wyniki po klasach i zawodnikach
        if ($isLongRange) {
            $aggregatedClasses = er_aggregate_long_range_results_by_class($rawResults);
        } else {
            $aggregatedClasses = er_aggregate_results_by_class($rawResults);
        }
        
        // Przetwórz każdą klasę
        $processedTables = [];
        foreach ($aggregatedClasses as $classKey => $participants) {
            if ($isLongRange) {
                $processedTables[$classKey] = er_process_long_range_class_results(
                    $participants, 
                    $sort, 
                    $direction,
                    $distanceLabel
                );
            } else {
                $processedTables[$classKey] = er_process_class_results(
                    $participants, 
                    $sort, 
                    $direction
                );
            }
        }
        
        // Pobierz metadane wydarzenia
        $metadata = er_fetch_event_metadata($day, $eventInfo);
        
        $result = [
            'meta' => array_merge($metadata, [
                'is_long_range' => $isLongRange,
                'distance_label' => $distanceLabel,
                'real_day' => $realDay
            ]),
            'tables' => $processedTables,
        ];
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $result, [
                'day' => $day,
                'sort' => $sort,
                'direction' => $direction,
                'type' => $cacheType
            ]);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error building event tables for {$day}: " . $e->getMessage());
        return ['meta' => ['day' => $day, 'opis' => ''], 'tables' => []];
    }
}

/**
 * Agreguje wyniki Long Range po klasach i zawodnikach
 * 
 * @param array $rawResults Surowe wyniki z bazy
 * @return array Zagregowane wyniki
 */
function er_aggregate_long_range_results_by_class(array $rawResults): array {
    $classes = [];
    
    foreach ($rawResults as $row) {
        $class = (string)($row['class'] ?? '');
        $fname = trim((string)($row['fname'] ?? ''));
        $lname = trim((string)($row['lname'] ?? ''));
        
        // Pomiń rekordy bez nazwiska
        if ($fname === '' && $lname === '') {
            continue;
        }
        
        // Utwórz unikalny klucz dla zawodnika
        $participantKey = er_create_participant_key($fname, $lname);
        
        if (!isset($classes[$class])) {
            $classes[$class] = [];
        }
        
        if (!isset($classes[$class][$participantKey])) {
            // Używamy tylko d4 dla Long Range
            $classes[$class][$participantKey] = [
                'fname' => $fname,
                'lname' => $lname,
                'res_d4' => (int)($row['res_d4'] ?? 0),
                'x_d4' => (int)($row['x_d4'] ?? 0),
                'moa_d4' => $row['moa_d4'],
            ];
        }
    }
    
    return $classes;
}

/**
 * Przetwarza wyniki Long Range dla jednej klasy
 * 
 * @param array $participants Lista uczestników klasy
 * @param string $sort Kolumna sortowania
 * @param string $direction Kierunek sortowania
 * @param string $distanceLabel Etykieta dystansu
 * @return array Przetworzone wyniki z rankingiem
 */
function er_process_long_range_class_results(
    array $participants, 
    string $sort, 
    string $direction,
    string $distanceLabel
): array {
    if (empty($participants)) {
        return [];
    }
    
    $results = [];
    
    // Oblicz statystyki dla każdego uczestnika
    foreach ($participants as $participant) {
        $stats = [
            'total' => (int)$participant['res_d4'],
            'sum_x' => (int)$participant['x_d4'],
            'avg_moa' => $participant['moa_d4'], // Dla jednego dystansu to nie jest średnia
            'distance_label' => $distanceLabel
        ];
        $results[] = array_merge($participant, $stats);
    }
    
    // Filtruj zawodników z wynikiem > 0
    $results = array_filter($results, function($participant) {
        return (int)$participant['total'] > 0;
    });
    
    if (empty($results)) {
        return [];
    }
    
    // Przypisz rangi według standardowych kryteriów
    $results = er_assign_ranks($results);
    
    // Sortuj według wybranego kryterium
    $results = er_sort_results($results, $sort, $direction);
    
    return array_values($results);
}

/**
 * Agreguje wyniki po klasach i zawodnikach (standardowe)
 * 
 * Grupuje wyniki po klasie i identyfikuje zawodników po (fname, lname).
 * Obsługuje duplikaty i agregację wyników.
 * 
 * @param array $rawResults Surowe wyniki z bazy
 * @return array Zagregowane wyniki [class => [participant_key => data]]
 */
function er_aggregate_results_by_class(array $rawResults): array {
    $classes = [];
    
    foreach ($rawResults as $row) {
        $class = (string)($row['class'] ?? '');
        $fname = trim((string)($row['fname'] ?? ''));
        $lname = trim((string)($row['lname'] ?? ''));
        
        // Pomiń rekordy bez nazwiska
        if ($fname === '' && $lname === '') {
            continue;
        }
        
        // Utwórz unikalny klucz dla zawodnika
        $participantKey = er_create_participant_key($fname, $lname);
        
        if (!isset($classes[$class])) {
            $classes[$class] = [];
        }
        
        if (!isset($classes[$class][$participantKey])) {
            // Nowy zawodnik - używamy d1, d2, d3 (NIE d4)
            $classes[$class][$participantKey] = [
                'fname' => $fname,
                'lname' => $lname,
                'res_d1' => (int)($row['res_d1'] ?? 0),
                'res_d2' => (int)($row['res_d2'] ?? 0),
                'res_d3' => (int)($row['res_d3'] ?? 0),
                'x_d1' => (int)($row['x_d1'] ?? 0),
                'x_d2' => (int)($row['x_d2'] ?? 0),
                'x_d3' => (int)($row['x_d3'] ?? 0),
                'moa_d1' => $row['moa_d1'],
                'moa_d2' => $row['moa_d2'],
                'moa_d3' => $row['moa_d3'],
            ];
        } else {
            // Agreguj wyniki dla istniejącego zawodnika
            $existing = &$classes[$class][$participantKey];
            
            $existing['res_d1'] += (int)($row['res_d1'] ?? 0);
            $existing['res_d2'] += (int)($row['res_d2'] ?? 0);
            $existing['res_d3'] += (int)($row['res_d3'] ?? 0);
            $existing['x_d1'] += (int)($row['x_d1'] ?? 0);
            $existing['x_d2'] += (int)($row['x_d2'] ?? 0);
            $existing['x_d3'] += (int)($row['x_d3'] ?? 0);
            
            // Dla MOA bierz minimum (najlepszy wynik)
            foreach (['moa_d1', 'moa_d2', 'moa_d3'] as $moaField) {
                $newMoa = $row[$moaField];
                if ($newMoa !== null) {
                    $currentMoa = $existing[$moaField];
                    if ($currentMoa === null || (float)$newMoa < (float)$currentMoa) {
                        $existing[$moaField] = $newMoa;
                    }
                }
            }
        }
    }
    
    return $classes;
}

/**
 * Tworzy unikalny klucz dla zawodnika
 * 
 * @param string $fname Imię
 * @param string $lname Nazwisko
 * @return string Unikalny klucz
 */
function er_create_participant_key(string $fname, string $lname): string {
    $normalizedFname = mb_strtolower(trim($fname), 'UTF-8');
    $normalizedLname = mb_strtolower(trim($lname), 'UTF-8');
    
    return hash('sha256', $normalizedFname . '|' . $normalizedLname);
}

/**
 * Przetwarza wyniki dla jednej klasy (standardowe)
 * 
 * Oblicza statystyki, ranking i sortuje według podanych kryteriów.
 * 
 * @param array $participants Lista uczestników klasy
 * @param string $sort Kolumna sortowania
 * @param string $direction Kierunek sortowania
 * @return array Przetworzone wyniki z rankingiem
 */
function er_process_class_results(array $participants, string $sort, string $direction): array {
    if (empty($participants)) {
        return [];
    }
    
    $results = [];
    
    // Oblicz statystyki dla każdego uczestnika
    foreach ($participants as $participant) {
        $stats = er_calculate_participant_stats($participant);
        $results[] = array_merge($participant, $stats);
    }
    
    // Filtruj zawodników z wynikiem > 0
    $results = array_filter($results, function($participant) {
        return (int)$participant['total'] > 0;
    });
    
    if (empty($results)) {
        return [];
    }
    
    // Przypisz rangi według standardowych kryteriów
    $results = er_assign_ranks($results);
    
    // Sortuj według wybranego kryterium
    $results = er_sort_results($results, $sort, $direction);
    
    return array_values($results);
}

/**
 * Oblicza statystyki dla uczestnika
 * 
 * @param array $participant Dane uczestnika
 * @return array Obliczone statystyki
 */
function er_calculate_participant_stats(array $participant): array {
    $total = (int)$participant['res_d1'] + (int)$participant['res_d2'] + (int)$participant['res_d3'];
    $sumX = (int)$participant['x_d1'] + (int)$participant['x_d2'] + (int)$participant['x_d3'];
    
    // Oblicz średnią MOA
    $moaValues = array_filter([
        $participant['moa_d1'],
        $participant['moa_d2'],
        $participant['moa_d3']
    ], function($value) {
        return $value !== null && $value !== '';
    });
    
    $avgMoa = null;
    if (count($moaValues) === 3) {
        $avgMoa = array_sum(array_map('floatval', $moaValues)) / 3.0;
    }
    
    return [
        'total' => $total,
        'sum_x' => $sumX,
        'avg_moa' => $avgMoa,
    ];
}

/**
 * Przypisuje rangi według standardowych kryteriów rankingowych
 * 
 * @param array $results Lista wyników
 * @return array Wyniki z przypisanymi rangami
 */
function er_assign_ranks(array $results): array {
    // Sortuj według kryteriów rankingowych (niezależnie od user sort)
    usort($results, function($a, $b) {
        // 1. Total score (DESC)
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        
        // 2. Sum of X (DESC)
        if ($a['sum_x'] !== $b['sum_x']) {
            return $b['sum_x'] <=> $a['sum_x'];
        }
        
        // 3. Average MOA (ASC - lower is better)
        $amoaIsNull = ($a['avg_moa'] === null);
        $bmoaIsNull = ($b['avg_moa'] === null);
        
        if ($amoaIsNull !== $bmoaIsNull) {
            return $amoaIsNull ? 1 : -1; // null values last
        }
        
        if (!$amoaIsNull && !$bmoaIsNull) {
            $moaDiff = (float)$a['avg_moa'] - (float)$b['avg_moa'];
            if (abs($moaDiff) > 0.001) { // Floating point comparison
                return $moaDiff > 0 ? 1 : -1;
            }
        }
        
        // 4. Alphabetical by lastname, firstname
        $lastNameCmp = strcmp($a['lname'], $b['lname']);
        if ($lastNameCmp !== 0) {
            return $lastNameCmp;
        }
        
        return strcmp($a['fname'], $b['fname']);
    });
    
    // Przypisz rangi
    $rank = 1;
    $previousSignature = null;
    
    foreach ($results as $index => &$result) {
        $signature = json_encode([
            $result['total'],
            $result['sum_x'],
            $result['avg_moa'],
            $result['lname'],
            $result['fname']
        ]);
        
        if ($previousSignature === null || $signature !== $previousSignature) {
            $rank = $index + 1;
        }
        
        $result['rank'] = $rank;
        $previousSignature = $signature;
    }
    unset($result);
    
    return $results;
}

/**
 * Sortuje wyniki według podanych kryteriów
 * 
 * @param array $results Lista wyników z rangami
 * @param string $sort Kolumna sortowania
 * @param string $direction Kierunek sortowania
 * @return array Posortowane wyniki
 */
function er_sort_results(array $results, string $sort, string $direction): array {
    $multiplier = ($direction === 'asc') ? 1 : -1;
    
    usort($results, function($a, $b) use ($sort, $multiplier) {
        $valueA = $a[$sort] ?? null;
        $valueB = $b[$sort] ?? null;
        
        // Obsługa null values (zawsze na końcu)
        $aIsNull = ($valueA === null);
        $bIsNull = ($valueB === null);
        
        if ($aIsNull !== $bIsNull) {
            return $aIsNull ? 1 : -1;
        }
        
        if (!$aIsNull && !$bIsNull) {
            // Sortowanie numeryczne vs alfabetyczne
            if (is_numeric($valueA) && is_numeric($valueB)) {
                $comparison = ((float)$valueA) <=> ((float)$valueB);
            } else {
                $comparison = strcmp((string)$valueA, (string)$valueB);
            }
            
            if ($comparison !== 0) {
                return $comparison * $multiplier;
            }
        }
        
        // Tie-breaker: alfabetycznie po nazwisku, imieniu
        $lastNameCmp = strcmp($a['lname'], $b['lname']);
        if ($lastNameCmp !== 0) {
            return $lastNameCmp;
        }
        
        return strcmp($a['fname'], $b['fname']);
    });
    
    return $results;
}

/**
 * Waliduje kolumnę sortowania
 * 
 * @param string|null $sort Kolumna do walidacji
 * @param bool $isLongRange Czy to Long Range
 * @return string Poprawna kolumna sortowania
 */
function er_validate_sort_column(?string $sort, bool $isLongRange = false): string {
    $config = require __DIR__ . '/config.php';
    
    if ($isLongRange) {
        // Dla Long Range dozwolone kolumny
        $allowedColumns = [
            'rank', 'total', 'sum_x', 'avg_moa',
            'res_d4', 'x_d4', 'moa_d4',
            'fname', 'lname'
        ];
    } else {
        // Standardowe kolumny
        $allowedColumns = [
            'rank', 'total', 'sum_x', 'avg_moa',
            'res_d1', 'x_d1', 'moa_d1',
            'res_d2', 'x_d2', 'moa_d2',
            'res_d3', 'x_d3', 'moa_d3',
            'fname', 'lname'
        ];
    }
    
    if ($sort !== null && in_array($sort, $allowedColumns, true)) {
        return $sort;
    }
    
    return $config['constants']['default_sort'];
}

/**
 * Waliduje kierunek sortowania
 * 
 * @param string $direction Kierunek do walidacji
 * @return string Poprawny kierunek sortowania
 */
function er_validate_sort_direction(string $direction): string {
    $config = require __DIR__ . '/config.php';
    
    $normalized = strtolower(trim($direction));
    
    if (in_array($normalized, ['asc', 'desc'], true)) {
        return $normalized;
    }
    
    return $config['constants']['default_direction'];
}

/**
 * Pobiera metadane wydarzenia
 * 
 * @param string $day Dzień wydarzenia
 * @param array|null $eventInfo Informacje o wydarzeniu
 * @return array Metadane wydarzenia
 */
function er_fetch_event_metadata(string $day, ?array $eventInfo = null): array {
    if ($eventInfo !== null) {
        return [
            'day' => $day,
            'opis' => $eventInfo['opis'] ?? '',
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT opis FROM zawody WHERE data = ? LIMIT 1");
        $stmt->execute([(int)$day]);
        $row = $stmt->fetch();
        
        $opis = '';
        if ($row && isset($row['opis'])) {
            $opis = clean_for_db($row['opis']);
        }
        
        return [
            'day' => $day,
            'opis' => $opis,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        
    } catch (Exception $e) {
        error_log("Error fetching event metadata for {$day}: " . $e->getMessage());
        
        return [
            'day' => $day,
            'opis' => '',
            'generated_at' => date('Y-m-d H:i:s'),
            'error' => 'Could not fetch metadata',
        ];
    }
}

/**
 * Czyści cache dla wydarzeń
 * 
 * @param string|null $day Konkretny dzień (null = wszystkie)
 * @return int Liczba usuniętych wpisów cache
 */
function er_clear_event_cache(?string $day = null): int {
    if ($day !== null) {
        // Usuń cache dla konkretnego dnia (standard i long range)
        $patterns = [
            "event_tables_{$day}_std_*",
            "event_tables_{$day}_lr_*",
            "events_year_" . substr($day, 0, 4) . "_with_lr",
        ];
        
        $deleted = 0;
        foreach ($patterns as $pattern) {
            if (cache_delete($pattern)) {
                $deleted++;
            }
        }
        
        return $deleted;
    } else {
        // Usuń wszystkie wpisy związane z wydarzeniami
        return cache_cleanup();
    }
}
?>      return cache_cleanup();
    }
}
?>