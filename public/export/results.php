<?php
/**
 * Eksport wyników wydarzeń
 * 
 * Eksportuje wyniki konkretnych zawodów do formatów CSV i JSON
 * z obsługą różnych opcji formatowania i metadanych.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

// === BEZPIECZEŃSTWO I INICJALIZACJA ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/../../app/event_results.php';
require_once __DIR__ . '/../../app/classmap.php';
require_once __DIR__ . '/../../app/encoding.php';
require_once __DIR__ . '/../../app/cache.php';

// === KONFIGURACJA ===
$config = require __DIR__ . '/../../app/config.php';

try {
    // === WALIDACJA PARAMETRÓW ===
    $dayParam = sanitize_input($_GET['day'] ?? '', ['max_length' => 10]);
    $format = sanitize_input($_GET['fmt'] ?? 'csv', ['max_length' => 10]);
    $classFilter = sanitize_input($_GET['class'] ?? '', ['max_length' => 20]);
    $sortParam = sanitize_input($_GET['sort'] ?? 'rank', ['max_length' => 20]);
    $dirParam = sanitize_input($_GET['dir'] ?? 'asc', ['max_length' => 4]);
    $includeEmpty = !empty($_GET['include_empty']);
    $includeMetadata = !empty($_GET['include_metadata']);
    
    // Waliduj format
    $allowedFormats = $config['constants']['export_formats'];
    if (!in_array($format, $allowedFormats, true)) {
        throw new InvalidArgumentException("Invalid format: {$format}");
    }
    
    // === OBSŁUGA SPECJALNYCH PRZYPADKÓW ===
    
    // Przypadek 'last' - znajdź najnowsze zawody
    if ($dayParam === 'last') {
        $day = resolve_last_event_day();
        
        // Dla formatu JSON zwróć tylko metadane (bootstrap dla SPA)
        if ($format === 'json' && !$includeMetadata) {
            export_last_event_metadata($day);
            exit;
        }
    } else {
        $day = $dayParam;
    }
    
    // Waliduj dzień
    if ($day === '') {
        throw new InvalidArgumentException('Missing or invalid day parameter');
    }
    
    $normalizedDay = er_normalize_day_table($day);
    if ($normalizedDay === null) {
        throw new InvalidArgumentException("Invalid day format: {$day}");
    }
    
    // === POBIERANIE DANYCH ===
    $sort = er_validate_sort_column($sortParam);
    $direction = er_validate_sort_direction($dirParam);
    
    $cacheKey = "export_results_{$normalizedDay}_{$sort}_{$direction}_{$classFilter}_{$format}";
    $cached = cache_get($cacheKey, 300); // 5 minut cache dla eksportu
    
    if ($cached !== null && !$includeEmpty) {
        $resultData = $cached;
    } else {
        $resultData = er_build_event_tables($normalizedDay, $sort, $direction);
        
        if (!$includeEmpty) {
            cache_set($cacheKey, $resultData);
        }
    }
    
    $metadata = $resultData['meta'];
    $tables = $resultData['tables'];
    
    // === FILTROWANIE DANYCH ===
    $filteredTables = filter_export_data($tables, $classFilter, $includeEmpty);
    
    if (empty($filteredTables)) {
        throw new RuntimeException('No data found for the specified criteria');
    }
    
    // === EKSPORT WEDŁUG FORMATU ===
    switch ($format) {
        case 'json':
            export_results_json($metadata, $filteredTables, $includeMetadata);
            break;
            
        case 'csv':
            export_results_csv($metadata, $filteredTables, $normalizedDay);
            break;
            
        default:
            throw new InvalidArgumentException("Unsupported export format: {$format}");
    }
    
} catch (Exception $e) {
    // Log błędu
    error_log("Results export error: " . $e->getMessage());
    
    // Zwróć błąd w odpowiednim formacie
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    
    if (($format ?? 'csv') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Export Error: " . $e->getMessage();
    }
    
    exit;
}

/**
 * Znajduje dzień ostatniego wydarzenia
 * 
 * @return string Dzień w formacie YYYYMMDD
 * @throws RuntimeException
 */
function resolve_last_event_day(): string {
    $maxYear = er_fetch_max_year();
    $config = require __DIR__ . '/../../app/config.php';
    $minYear = $config['app']['min_year'];
    
    // Przeszukaj od najnowszego roku w dół
    for ($year = $maxYear; $year >= $minYear; $year--) {
        $events = er_fetch_events_for_year($year);
        if (!empty($events)) {
            $lastEvent = end($events);
            return $lastEvent['day'];
        }
    }
    
    throw new RuntimeException('No events found in any year');
}

/**
 * Eksportuje metadane ostatniego wydarzenia (dla API)
 * 
 * @param string $day Dzień wydarzenia
 * @return void
 */
function export_last_event_metadata(string $day): void {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $resultData = er_build_event_tables($day);
        $metadata = $resultData['meta'];
        
        echo json_encode([
            'success' => true,
            'meta' => $metadata,
            'day' => $day,
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Filtruje dane do eksportu
 * 
 * @param array $tables Tabele wyników
 * @param string $classFilter Filtr klasy
 * @param bool $includeEmpty Czy uwzględnić puste wyniki
 * @return array Przefiltrowane tabele
 */
function filter_export_data(array $tables, string $classFilter, bool $includeEmpty): array {
    $filtered = [];
    
    foreach ($tables as $classKey => $participants) {
        // Filtruj po klasie
        if ($classFilter !== '' && $classKey !== $classFilter) {
            continue;
        }
        
        // Filtruj puste wyniki
        if (!$includeEmpty) {
            $participants = array_filter($participants, function($participant) {
                return ($participant['total'] ?? 0) > 0;
            });
        }
        
        if (!empty($participants)) {
            $filtered[$classKey] = $participants;
        }
    }
    
    return $filtered;
}

/**
 * Eksportuje wyniki do formatu JSON
 * 
 * @param array $metadata Metadane wydarzenia
 * @param array $tables Tabele wyników
 * @param bool $includeMetadata Czy dołączyć rozszerzone metadane
 * @return void
 */
function export_results_json(array $metadata, array $tables, bool $includeMetadata): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_' . $metadata['day'] . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Przekształć dane do formatu JSON
    $rows = [];
    foreach ($tables as $classKey => $participants) {
        $className = class_map_name($classKey);
        
        foreach ($participants as $participant) {
            $row = [
                'class_id' => $classKey,
                'class_name' => $className,
                'rank' => (int)($participant['rank'] ?? 0),
                'fname' => $participant['fname'] ?? '',
                'lname' => $participant['lname'] ?? '',
                
                // Wyniki dystansów
                'distance_300m' => [
                    'result' => (int)($participant['res_d1'] ?? 0),
                    'x_count' => (int)($participant['x_d1'] ?? 0),
                    'moa' => $participant['moa_d1'],
                ],
                'distance_600m' => [
                    'result' => (int)($participant['res_d2'] ?? 0),
                    'x_count' => (int)($participant['x_d2'] ?? 0),
                    'moa' => $participant['moa_d2'],
                ],
                'distance_800m' => [
                    'result' => (int)($participant['res_d3'] ?? 0),
                    'x_count' => (int)($participant['x_d3'] ?? 0),
                    'moa' => $participant['moa_d3'],
                ],
                
                // Podsumowanie
                'total_score' => (int)($participant['total'] ?? 0),
                'total_x' => (int)($participant['sum_x'] ?? 0),
                'average_moa' => $participant['avg_moa'],
            ];
            
            $rows[] = $row;
        }
    }
    
    // Przygotuj odpowiedź
    $response = [
        'success' => true,
        'meta' => array_merge($metadata, [
            'export_timestamp' => date('Y-m-d H:i:s'),
            'export_format' => 'json',
            'total_participants' => count($rows),
            'total_classes' => count($tables),
        ]),
        'results' => $rows,
    ];
    
    // Dodaj rozszerzone metadane jeśli wymagane
    if ($includeMetadata) {
        global $config;
        $response['export_info'] = [
            'version' => $config['app']['version'],
            'generator' => $config['app']['name'],
            'data_structure' => [
                'distance_300m' => 'Results for 300 meter distance',
                'distance_600m' => 'Results for 600 meter distance', 
                'distance_800m' => 'Results for 800 meter distance',
                'result' => 'Points scored',
                'x_count' => 'Number of X-ring hits',
                'moa' => 'Minute of Angle precision',
                'total_score' => 'Sum of all distance results',
                'average_moa' => 'Average MOA across all distances (if all available)',
            ],
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/**
 * Eksportuje wyniki do formatu CSV
 * 
 * @param array $metadata Metadane wydarzenia
 * @param array $tables Tabele wyników
 * @param string $day Dzień wydarzenia
 * @return void
 */
function export_results_csv(array $metadata, array $tables, string $day): void {
    global $config;
    
    $csvConfig = $config['export']['csv'];
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_' . $day . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    $output = fopen('php://output', 'w');
    
    if (!$output) {
        throw new RuntimeException('Could not open output stream for CSV');
    }
    
    try {
        // UTF-8 BOM dla Excel
        if ($csvConfig['bom']) {
            fwrite($output, "\xEF\xBB\xBF");
        }
        
        // === METADANE W KOMENTARZACH ===
        if ($config['app']['allow_debug']) {
            $comments = [
                "# Event Results Export",
                "# Day: {$day}",
                "# Event: " . ($metadata['opis'] ?: 'N/A'),
                "# Generated: " . date('Y-m-d H:i:s'),
                "# Total classes: " . count($tables),
                "# Total participants: " . array_sum(array_map('count', $tables)),
                "#",
            ];
            
            foreach ($comments as $comment) {
                fwrite($output, $comment . "\n");
            }
        }
        
        // === NAGŁÓWEK CSV ===
        $headers = [
            'class_id',
            'class_name', 
            'rank',
            'fname',
            'lname',
            
            // 300m
            'res_300m',
            'x_300m',
            'moa_300m',
            
            // 600m
            'res_600m',
            'x_600m', 
            'moa_600m',
            
            // 800m
            'res_800m',
            'x_800m',
            'moa_800m',
            
            // Podsumowanie
            'total_score',
            'total_x',
            'average_moa',
        ];
        
        fputcsv($output, $headers, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
        
        // === DANE CSV ===
        // Sortuj klasy numerycznie
        $sortedClasses = sort_classes(array_keys($tables), 'numeric');
        
        foreach ($sortedClasses as $classKey) {
            $participants = $tables[$classKey];
            $className = class_map_name($classKey);
            
            foreach ($participants as $participant) {
                $row = [
                    $classKey,
                    $className,
                    (int)($participant['rank'] ?? 0),
                    $participant['fname'] ?? '',
                    $participant['lname'] ?? '',
                    
                    // 300m
                    (int)($participant['res_d1'] ?? 0),
                    (int)($participant['x_d1'] ?? 0),
                    $participant['moa_d1'] ?? '',
                    
                    // 600m  
                    (int)($participant['res_d2'] ?? 0),
                    (int)($participant['x_d2'] ?? 0),
                    $participant['moa_d2'] ?? '',
                    
                    // 800m
                    (int)($participant['res_d3'] ?? 0),
                    (int)($participant['x_d3'] ?? 0),
                    $participant['moa_d3'] ?? '',
                    
                    // Podsumowanie
                    (int)($participant['total'] ?? 0),
                    (int)($participant['sum_x'] ?? 0),
                    $participant['avg_moa'] ?? '',
                ];
                
                fputcsv($output, $row, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
            }
        }
        
        // === FOOTER ===
        if ($config['app']['allow_debug']) {
            fwrite($output, "\n# Data Dictionary:\n");
            fwrite($output, "# res_XXXm = Points scored at XXX meter distance\n");
            fwrite($output, "# x_XXXm = Number of X-ring hits at XXX meter distance\n");
            fwrite($output, "# moa_XXXm = Minute of Angle precision at XXX meter distance\n");
            fwrite($output, "# total_score = Sum of all distance results\n");
            fwrite($output, "# total_x = Sum of all X-ring hits\n");
            fwrite($output, "# average_moa = Average MOA (only if all 3 distances have MOA values)\n");
        }
        
    } finally {
        fclose($output);
    }
}
?>