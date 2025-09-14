<?php
/**
 * Eksport rankingu rocznego
 * 
 * Eksportuje dane rankingu rocznego do formatów CSV i JSON
 * z obsługą filtrowania, walidacji oraz różnych opcji formatowania.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

// === BEZPIECZEŃSTWO I INICJALIZACJA ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/../../app/query_ranking.php';
require_once __DIR__ . '/../../app/encoding.php';
require_once __DIR__ . '/../../app/classmap.php';
require_once __DIR__ . '/../../app/cache.php';

// === KONFIGURACJA ===
$config = require __DIR__ . '/../../app/config.php';

try {
    // === WALIDACJA PARAMETRÓW ===
    $year = sanitize_input($_GET['year'] ?? null, ['max_length' => 4]);
    $format = sanitize_input($_GET['fmt'] ?? 'csv', ['max_length' => 10]);
    $classFilter = sanitize_input($_GET['class'] ?? '', ['max_length' => 20]);
    $includeEmpty = !empty($_GET['include_empty']);
    
    // Waliduj rok
    if ($year === null || $year === '') {
        $year = $config['app']['year'];
    } else {
        $year = (int)$year;
        $maxYear = fetch_max_year();
        $minYear = $config['app']['min_year'];
        
        if ($year < $minYear || $year > $maxYear) {
            throw new InvalidArgumentException("Invalid year: {$year}. Must be between {$minYear} and {$maxYear}.");
        }
    }
    
    // Waliduj format
    $allowedFormats = $config['constants']['export_formats'];
    if (!in_array($format, $allowedFormats, true)) {
        throw new InvalidArgumentException("Invalid format: {$format}. Allowed: " . implode(', ', $allowedFormats));
    }
    
    // === POBIERANIE DANYCH ===
    $cacheKey = "export_ranking_{$year}_{$format}_{$classFilter}" . ($includeEmpty ? '_with_empty' : '');
    $cached = cache_get($cacheKey, 600); // 10 minut cache dla eksportu
    
    if ($cached !== null) {
        $rankingData = $cached;
    } else {
        $rankingData = build_annual_ranking_with_columns($year);
        cache_set($cacheKey, $rankingData);
    }
    
    $events = $rankingData['events'] ?? [];
    $data = $rankingData['data'] ?? [];
    $meta = $rankingData['meta'] ?? [];
    
    // Konwertuj do UTF-8
    $data = to_utf8($data);
    
    // === FILTROWANIE DANYCH ===
    $filteredData = [];
    
    foreach ($data as $classKey => $participants) {
        // Filtruj po klasie jeśli podano
        if ($classFilter !== '' && $classKey !== $classFilter) {
            continue;
        }
        
        // Filtruj puste wyniki jeśli nie ma flagi include_empty
        if (!$includeEmpty) {
            $participants = array_filter($participants, function($participant) {
                return !empty($participant) && ($participant['total'] ?? 0) > 0;
            });
        }
        
        if (!empty($participants)) {
            $filteredData[$classKey] = $participants;
        }
    }
    
    // === GENEROWANIE EKSPORTU ===
    $exportData = [
        'meta' => array_merge($meta, [
            'export_timestamp' => date('Y-m-d H:i:s'),
            'export_format' => $format,
            'year' => $year,
            'class_filter' => $classFilter ?: 'all',
            'include_empty' => $includeEmpty,
            'total_classes' => count($filteredData),
            'total_participants' => array_sum(array_map('count', $filteredData)),
        ]),
        'events' => $events,
        'data' => $filteredData,
    ];
    
    // === EKSPORT WEDŁUG FORMATU ===
    switch ($format) {
        case 'json':
            export_ranking_json($exportData);
            break;
            
        case 'csv':
            export_ranking_csv($exportData, $events, $filteredData);
            break;
            
        default:
            throw new InvalidArgumentException("Unsupported export format: {$format}");
    }
    
} catch (Exception $e) {
    // Log błędu
    error_log("Ranking export error: " . $e->getMessage());
    
    // Zwróć błąd w odpowiednim formacie
    http_response_code(400);
    
    if (($format ?? 'csv') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Export Error: " . $e->getMessage();
    }
    
    exit;
}

/**
 * Eksportuje dane do formatu JSON
 * 
 * @param array $data Dane do eksportu
 * @return void
 */
function export_ranking_json(array $data): void {
    global $config;
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="annual_ranking_' . $data['meta']['year'] . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    $flags = $config['export']['json']['flags'];
    if ($config['export']['json']['pretty_print']) {
        $flags |= JSON_PRETTY_PRINT;
    }
    
    $jsonOutput = json_encode($data, $flags);
    
    if ($jsonOutput === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }
    
    echo $jsonOutput;
    exit;
}

/**
 * Eksportuje dane do formatu CSV
 * 
 * @param array $exportData Pełne dane eksportu
 * @param array $events Lista wydarzeń
 * @param array $filteredData Przefiltrowane dane
 * @return void
 */
function export_ranking_csv(array $exportData, array $events, array $filteredData): void {
    global $config;
    
    $csvConfig = $config['export']['csv'];
    $year = $exportData['meta']['year'];
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="annual_ranking_' . $year . '.csv"');
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
        
        // === METADANE (opcjonalne komentarze w CSV) ===
        if ($config['app']['allow_debug']) {
            $metaComments = [
                "# Annual Ranking Export",
                "# Year: {$year}",
                "# Generated: " . $exportData['meta']['export_timestamp'],
                "# Classes: " . $exportData['meta']['total_classes'],
                "# Participants: " . $exportData['meta']['total_participants'],
                "# Events: " . count($events),
                "#",
            ];
            
            foreach ($metaComments as $comment) {
                fwrite($output, $comment . "\n");
            }
        }
        
        // === NAGŁÓWEK CSV ===
        $headers = ['class', 'class_name', 'rank', 'fname', 'lname'];
        
        // Dodaj kolumny wydarzeń
        foreach ($events as $event) {
            $headers[] = $event;
        }
        
        // Dodaj kolumny podsumowania
        $headers = array_merge($headers, [
            'total_score',
            'me_score',
            'best1_score', 
            'best2_score',
            'total_starts',
            'me_starts',
            'other_starts'
        ]);
        
        fputcsv($output, $headers, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
        
        // === DANE CSV ===
        foreach ($filteredData as $classKey => $participants) {
            $className = class_map_name($classKey);
            
            foreach ($participants as $participant) {
                $row = [
                    $classKey,
                    $className,
                    $participant['rank'] ?? '',
                    $participant['fname'] ?? '',
                    $participant['lname'] ?? '',
                ];
                
                // Dodaj wyniki wydarzeń
                foreach ($events as $event) {
                    $cellData = $participant['cells'][$event] ?? null;
                    $score = null;
                    
                    if ($cellData && $cellData['score'] !== null) {
                        $score = (float)$cellData['score'];
                        
                        // Oznacz wybrane wyniki gwiazdką
                        if ($cellData['selected']) {
                            $score = $score . '*';
                        }
                    }
                    
                    $row[] = $score;
                }
                
                // Dodaj podsumowania
                $row = array_merge($row, [
                    $participant['total'] ?? 0,
                    $participant['me_score'] ?? 0,
                    $participant['best1_score'] ?? 0,
                    $participant['best2_score'] ?? 0,
                    $participant['total_starts'] ?? 0,
                    $participant['me_starts'] ?? 0,
                    $participant['other_starts'] ?? 0,
                ]);
                
                fputcsv($output, $row, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
            }
        }
        
        // === FOOTER (dodatkowe informacje) ===
        if ($config['app']['allow_debug']) {
            fwrite($output, "\n# Legend:\n");
            fwrite($output, "# * = Score used in annual ranking calculation\n");
            fwrite($output, "# ME = European Championships\n");
            fwrite($output, "# Qualification: >= 1 ME start + >= 2 other starts (with score > 0)\n");
            fwrite($output, "# Annual score = Best ME + Best 2 other events\n");
        }
        
    } finally {
        fclose($output);
    }
    
    exit;
}
?>