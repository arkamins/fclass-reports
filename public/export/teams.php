<?php
/**
 * Eksport zespołów ME
 * 
 * Eksportuje dane zespołów Mistrzostw Europy do formatów CSV i JSON
 * z obsługą filtrowania, walidacji oraz metadanych.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

// === BEZPIECZEŃSTWO I INICJALIZACJA ===
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require_once __DIR__ . '/../../app/teams.php';
require_once __DIR__ . '/../../app/encoding.php';
require_once __DIR__ . '/../../app/cache.php';

// === KONFIGURACJA ===
$config = require __DIR__ . '/../../app/config.php';

try {
    // === WALIDACJA PARAMETRÓW ===
    $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
    $format = sanitize_input($_GET['fmt'] ?? 'csv', ['max_length' => 10]);
    $classFilter = sanitize_input($_GET['class'] ?? '', ['max_length' => 20]);
    $includeInvalid = !empty($_GET['include_invalid']);
    $includeMetadata = !empty($_GET['include_metadata']);
    $searchTerm = sanitize_input($_GET['search'] ?? '', ['max_length' => 50]);
    
    // Waliduj dzień
    if ($day === '') {
        throw new InvalidArgumentException('Missing day parameter');
    }
    
    $normalizedDay = t_normalize_day($day);
    if ($normalizedDay === null) {
        throw new InvalidArgumentException("Invalid day format: {$day}");
    }
    
    // Waliduj format
    $allowedFormats = $config['constants']['export_formats'];
    if (!in_array($format, $allowedFormats, true)) {
        throw new InvalidArgumentException("Invalid format: {$format}");
    }
    
    // === POBIERANIE DANYCH ===
    $cacheKey = "export_teams_{$normalizedDay}_{$format}_{$classFilter}" . 
                ($includeInvalid ? '_with_invalid' : '') .
                ($searchTerm ? '_search_' . md5($searchTerm) : '');
    
    $cached = cache_get($cacheKey, 300); // 5 minut cache dla eksportu
    
    if ($cached !== null) {
        $teamsData = $cached;
    } else {
        $teamsData = t_fetch_teams_by_class($normalizedDay);
        
        if (!$includeInvalid || $searchTerm === '') {
            cache_set($cacheKey, $teamsData);
        }
    }
    
    if (empty($teamsData)) {
        throw new RuntimeException('No teams data found for the specified day');
    }
    
    // === FILTROWANIE DANYCH ===
    $filteredData = filter_teams_export_data($teamsData, $classFilter, $includeInvalid, $searchTerm);
    
    if (empty($filteredData)) {
        throw new RuntimeException('No teams found matching the specified criteria');
    }
    
    // === METADANE ===
    $metadata = generate_teams_export_metadata($normalizedDay, $filteredData, [
        'class_filter' => $classFilter,
        'include_invalid' => $includeInvalid,
        'search_term' => $searchTerm,
        'format' => $format,
    ]);
    
    // === EKSPORT WEDŁUG FORMATU ===
    switch ($format) {
        case 'json':
            export_teams_json($metadata, $filteredData, $includeMetadata);
            break;
            
        case 'csv':
            export_teams_csv($metadata, $filteredData, $normalizedDay);
            break;
            
        default:
            throw new InvalidArgumentException("Unsupported export format: {$format}");
    }
    
} catch (Exception $e) {
    // Log błędu
    error_log("Teams export error: " . $e->getMessage());
    
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
 * Filtruje dane zespołów do eksportu
 * 
 * @param array $teamsData Dane zespołów
 * @param string $classFilter Filtr klasy
 * @param bool $includeInvalid Czy uwzględnić niepełne zespoły
 * @param string $searchTerm Wyszukiwana fraza
 * @return array Przefiltrowane dane
 */
function filter_teams_export_data(array $teamsData, string $classFilter, bool $includeInvalid, string $searchTerm): array {
    $filtered = [];
    
    foreach ($teamsData as $classId => $classData) {
        // Filtruj po klasie
        if ($classFilter !== '' && $classId !== $classFilter) {
            continue;
        }
        
        $teams = $classData['teams'];
        
        // Filtruj niepełne zespoły
        if (!$includeInvalid) {
            $teams = array_filter($teams, function($team) {
                return $team['valid_team'] ?? false;
            });
        }
        
        // Filtruj po wyszukiwanej frazie
        if ($searchTerm !== '') {
            $teams = filter_teams_by_search_term($teams, $searchTerm);
        }
        
        if (!empty($teams)) {
            $filtered[$classId] = [
                'class_name' => $classData['class_name'],
                'teams' => array_values($teams),
            ];
        }
    }
    
    return $filtered;
}

/**
 * Filtruje zespoły według wyszukiwanej frazy
 * 
 * @param array $teams Lista zespołów
 * @param string $searchTerm Wyszukiwana fraza
 * @return array Przefiltrowane zespoły
 */
function filter_teams_by_search_term(array $teams, string $searchTerm): array {
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    
    return array_filter($teams, function($team) use ($searchLower) {
        // Szukaj w nazwie zespołu
        $teamName = mb_strtolower($team['team_name'], 'UTF-8');
        if (mb_strpos($teamName, $searchLower, 0, 'UTF-8') !== false) {
            return true;
        }
        
        // Szukaj w nazwiskach członków
        $member1Name = mb_strtolower($team['member1']['full_name'] ?? '', 'UTF-8');
        $member2Name = mb_strtolower($team['member2']['full_name'] ?? '', 'UTF-8');
        
        return mb_strpos($member1Name, $searchLower, 0, 'UTF-8') !== false ||
               mb_strpos($member2Name, $searchLower, 0, 'UTF-8') !== false;
    });
}

/**
 * Generuje metadane eksportu zespołów
 * 
 * @param string $day Dzień wydarzenia
 * @param array $filteredData Przefiltrowane dane
 * @param array $exportOptions Opcje eksportu
 * @return array Metadane
 */
function generate_teams_export_metadata(string $day, array $filteredData, array $exportOptions): array {
    // Znajdź informacje o wydarzeniu
    $eventInfo = null;
    $year = (int)substr($day, 0, 4);
    $events = t_fetch_me_events_for_year($year);
    
    foreach ($events as $event) {
        if ($event['day'] === $day) {
            $eventInfo = $event;
            break;
        }
    }
    
    // Oblicz statystyki
    $totalTeams = 0;
    $validTeams = 0;
    
    foreach ($filteredData as $classData) {
        foreach ($classData['teams'] as $team) {
            $totalTeams++;
            if ($team['valid_team'] ?? false) {
                $validTeams++;
            }
        }
    }
    
    return [
        'day' => $day,
        'formatted_date' => $eventInfo ? $eventInfo['formatted_date'] : t_format_event_date($day),
        'event_description' => $eventInfo ? $eventInfo['opis'] : '',
        'year' => $year,
        'export_timestamp' => date('Y-m-d H:i:s'),
        'export_format' => $exportOptions['format'],
        'filters' => [
            'class' => $exportOptions['class_filter'] ?: 'all',
            'include_invalid' => $exportOptions['include_invalid'],
            'search_term' => $exportOptions['search_term'] ?: null,
        ],
        'statistics' => [
            'total_classes' => count($filteredData),
            'total_teams' => $totalTeams,
            'valid_teams' => $validTeams,
            'invalid_teams' => $totalTeams - $validTeams,
            'total_participants' => $totalTeams * 2,
        ],
    ];
}

/**
 * Eksportuje zespoły do formatu JSON
 * 
 * @param array $metadata Metadane eksportu
 * @param array $filteredData Przefiltrowane dane
 * @param bool $includeMetadata Czy dołączyć rozszerzone metadane
 * @return void
 */
function export_teams_json(array $metadata, array $filteredData, bool $includeMetadata): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="teams_' . $metadata['day'] . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Przekształć dane do formatu JSON
    $classes = [];
    
    foreach ($filteredData as $classId => $classData) {
        $teamsJson = [];
        
        foreach ($classData['teams'] as $team) {
            $teamJson = [
                'rank' => (int)($team['rank'] ?? 0),
                'team_name' => $team['team_name'] ?? '',
                'team_total' => (float)($team['team_total'] ?? 0),
                'valid_team' => (bool)($team['valid_team'] ?? false),
                'members' => [
                    [
                        'id' => $team['member1']['id'] ?? '',
                        'first_name' => $team['member1']['fname'] ?? '',
                        'last_name' => $team['member1']['lname'] ?? '',
                        'full_name' => $team['member1']['full_name'] ?? '',
                        'total_score' => (float)($team['member1']['total'] ?? 0),
                        'valid' => (bool)($team['member1']['valid'] ?? false),
                    ],
                    [
                        'id' => $team['member2']['id'] ?? '',
                        'first_name' => $team['member2']['fname'] ?? '',
                        'last_name' => $team['member2']['lname'] ?? '',
                        'full_name' => $team['member2']['full_name'] ?? '',
                        'total_score' => (float)($team['member2']['total'] ?? 0),
                        'valid' => (bool)($team['member2']['valid'] ?? false),
                    ],
                ],
            ];
            
            $teamsJson[] = $teamJson;
        }
        
        $classes[] = [
            'class_id' => $classId,
            'class_name' => $classData['class_name'],
            'teams_count' => count($teamsJson),
            'teams' => $teamsJson,
        ];
    }
    
    // Przygotuj odpowiedź
    $response = [
        'success' => true,
        'meta' => $metadata,
        'classes' => $classes,
    ];
    
    // Dodaj rozszerzone metadane jeśli wymagane
    if ($includeMetadata) {
        global $config;
        $response['export_info'] = [
            'version' => $config['app']['version'],
            'generator' => $config['app']['name'],
            'data_structure' => [
                'classes' => 'Competition classes array',
                'teams' => 'Teams array for each class',
                'members' => 'Array of 2 team members',
                'rank' => 'Team position in class ranking',
                'team_total' => 'Sum of both members scores',
                'valid_team' => 'Whether team has complete member data',
                'total_score' => 'Individual member total score (sum of all distances)',
            ],
            'ranking_rules' => [
                '1' => 'Team total score (descending)',
                '2' => 'Best member score (descending)',
                '3' => 'Second member score (descending)', 
                '4' => 'Team name (alphabetical)',
            ],
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/**
 * Eksportuje zespoły do formatu CSV
 * 
 * @param array $metadata Metadane eksportu
 * @param array $filteredData Przefiltrowane dane
 * @param string $day Dzień wydarzenia
 * @return void
 */
function export_teams_csv(array $metadata, array $filteredData, string $day): void {
    global $config;
    
    $csvConfig = $config['export']['csv'];
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teams_' . $day . '.csv"');
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
                "# Teams Export - European Championships",
                "# Day: {$day}",
                "# Event: " . ($metadata['event_description'] ?: 'N/A'),
                "# Date: " . $metadata['formatted_date'],
                "# Generated: " . $metadata['export_timestamp'],
                "# Total classes: " . $metadata['statistics']['total_classes'],
                "# Total teams: " . $metadata['statistics']['total_teams'],
                "# Valid teams: " . $metadata['statistics']['valid_teams'],
                "#",
            ];
            
            if ($metadata['filters']['class'] !== 'all') {
                $comments[] = "# Class filter: " . $metadata['filters']['class'];
            }
            
            if ($metadata['filters']['search_term']) {
                $comments[] = "# Search term: \"" . $metadata['filters']['search_term'] . "\"";
            }
            
            $comments[] = "#";
            
            foreach ($comments as $comment) {
                fwrite($output, $comment . "\n");
            }
        }
        
        // === NAGŁÓWEK CSV ===
        $headers = [
            'class_id',
            'class_name',
            'rank',
            'team_name',
            'team_total',
            'valid_team',
            
            // Członek 1
            'member1_id',
            'member1_first_name',
            'member1_last_name',
            'member1_full_name',
            'member1_total',
            'member1_valid',
            
            // Członek 2
            'member2_id',
            'member2_first_name',
            'member2_last_name', 
            'member2_full_name',
            'member2_total',
            'member2_valid',
        ];
        
        fputcsv($output, $headers, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
        
        // === DANE CSV ===
        // Sortuj klasy numerycznie
        $sortedClasses = sort_classes(array_keys($filteredData), 'numeric');
        
        foreach ($sortedClasses as $classId) {
            $classData = $filteredData[$classId];
            $className = $classData['class_name'];
            
            foreach ($classData['teams'] as $team) {
                $row = [
                    $classId,
                    $className,
                    (int)($team['rank'] ?? 0),
                    $team['team_name'] ?? '',
                    (float)($team['team_total'] ?? 0),
                    ($team['valid_team'] ?? false) ? 'true' : 'false',
                    
                    // Członek 1
                    $team['member1']['id'] ?? '',
                    $team['member1']['fname'] ?? '',
                    $team['member1']['lname'] ?? '',
                    $team['member1']['full_name'] ?? '',
                    (float)($team['member1']['total'] ?? 0),
                    ($team['member1']['valid'] ?? false) ? 'true' : 'false',
                    
                    // Członek 2
                    $team['member2']['id'] ?? '',
                    $team['member2']['fname'] ?? '',
                    $team['member2']['lname'] ?? '',
                    $team['member2']['full_name'] ?? '',
                    (float)($team['member2']['total'] ?? 0),
                    ($team['member2']['valid'] ?? false) ? 'true' : 'false',
                ];
                
                fputcsv($output, $row, $csvConfig['delimiter'], $csvConfig['enclosure'], $csvConfig['escape']);
            }
        }
        
        // === FOOTER ===
        if ($config['app']['allow_debug']) {
            fwrite($output, "\n# Data Dictionary:\n");
            fwrite($output, "# class_id = Competition class identifier\n");
            fwrite($output, "# class_name = Human-readable class name\n");
            fwrite($output, "# rank = Team position in class ranking\n");
            fwrite($output, "# team_total = Sum of both members' total scores\n");
            fwrite($output, "# valid_team = Whether team has complete member data\n");
            fwrite($output, "# member*_total = Individual member's total score (sum of all distances)\n");
            fwrite($output, "# member*_valid = Whether member has valid data\n");
            fwrite($output, "#\n");
            fwrite($output, "# Ranking Rules:\n");
            fwrite($output, "# 1. Team total score (descending)\n");
            fwrite($output, "# 2. Best member score (descending)\n");
            fwrite($output, "# 3. Second member score (descending)\n");
            fwrite($output, "# 4. Team name (alphabetical)\n");
        }
        
    } finally {
        fclose($output);
    }
}
?>