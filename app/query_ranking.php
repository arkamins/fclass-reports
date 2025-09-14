<?php
/**
 * Zarządzanie rankingiem rocznym zawodów strzeleckich
 * 
 * Obsługuje kwalifikację, obliczanie wyników rocznych oraz prezentację
 * rankingu z obsługą różnych formatów i kryteriów sortowania.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/encoding.php';

/**
 * Alias dla normalizacji tabeli (zgodność z event_results)
 * 
 * @param string $day Dzień w formacie YYYYMMDD
 * @return string|null Znormalizowana nazwa lub null
 */
function normalize_day_table(string $day): ?string {
    require_once __DIR__ . '/event_results.php';
    return er_normalize_day_table($day);
}

/**
 * Alias dla maksymalnego roku (zgodność z event_results)
 * 
 * @param bool $useCache Czy używać cache
 * @return int Maksymalny dostępny rok
 */
function fetch_max_year(bool $useCache = true): int {
    require_once __DIR__ . '/event_results.php';
    return er_fetch_max_year($useCache);
}

/**
 * Pobiera wydarzenia dla roku z klasyfikacją ME
 * 
 * Zwraca listę wydarzeń z informacją czy to Mistrzostwa Europy.
 * Używa cache dla optymalizacji wydajności.
 * 
 * @param int $year Rok do wyszukania
 * @param bool $useCache Czy używać cache
 * @return array Lista wydarzeń z flagą is_me
 */
function fetch_events_for_year(int $year, bool $useCache = true): array {
    $cacheKey = "ranking_events_{$year}";
    
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
            $normalizedDay = normalize_day_table($day);
            
            if ($normalizedDay === null) {
                continue;
            }
            
            // Sprawdź czy tabela istnieje
            if (!tableExists($normalizedDay)) {
                continue;
            }
            
            $opis = clean_for_db($row['opis']);
            $isME = (stripos($opis, $mePhrase) !== false);
            
            $events[] = [
                'day' => $normalizedDay,
                'table' => $normalizedDay,
                'is_me' => $isME,
                'opis' => $opis,
            ];
        }
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $events);
        }
        
        return $events;
        
    } catch (Exception $e) {
        error_log("Error fetching ranking events for year {$year}: " . $e->getMessage());
        return [];
    }
}

/**
 * Pobiera zagregowane wyniki dla wydarzenia
 * 
 * Agreguje wyniki po klasie, imieniu i nazwisku z sumowaniem punktów i centralnych dziesiątek.
 * 
 * @param string $table Nazwa tabeli wydarzenia
 * @return array Wyniki pogrupowane po klasach
 */
function fetch_event_results(string $table): array {
    // Waliduj nazwę tabeli
    if (!validateTableName($table)) {
        error_log("Invalid table name for ranking: {$table}");
        return [];
    }
    
    // Sprawdź czy tabela istnieje
    if (!tableExists($table)) {
        error_log("Table does not exist for ranking: {$table}");
        return [];
    }
    
    try {
        $pdo = db();
        
        $sql = "
            SELECT
                class,
                TRIM(COALESCE(fname,'')) AS fname,
                TRIM(COALESCE(lname,'')) AS lname,
                COALESCE(SUM(
                    COALESCE(res_d1,0) + 
                    COALESCE(res_d2,0) + 
                    COALESCE(res_d3,0)
                ), 0) AS total,
                COALESCE(SUM(
                    COALESCE(x_d1, 0) + 
                    COALESCE(x_d2, 0) + 
                    COALESCE(x_d3, 0)
                ), 0) AS tens
            FROM `{$table}`
            WHERE TRIM(COALESCE(fname,'')) <> '' 
              AND TRIM(COALESCE(lname,'')) <> ''
            GROUP BY class, TRIM(COALESCE(fname,'')), TRIM(COALESCE(lname,''))
            HAVING total > 0
        ";
        
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        
        $byClass = [];
        foreach ($rows as $row) {
            $class = (string)$row['class'];
            $fname = (string)$row['fname'];
            $lname = (string)$row['lname'];
            $total = (float)$row['total'];
            $tens = (int)$row['tens'];
            
            if (!isset($byClass[$class])) {
                $byClass[$class] = [];
            }
            
            $byClass[$class][] = [
                'key' => qr_create_participant_key($fname, $lname),
                'fname' => $fname,
                'lname' => $lname,
                'total' => $total,
                'tens' => $tens,
            ];
        }
        
        return $byClass;
        
    } catch (Exception $e) {
        error_log("Error fetching event results for ranking table {$table}: " . $e->getMessage());
        return [];
    }
}

/**
 * Tworzy unikalny klucz dla uczestnika rankingu
 * 
 * @param string $fname Imię
 * @param string $lname Nazwisko
 * @return string Unikalny klucz
 */
function qr_create_participant_key(string $fname, string $lname): string {
    $normalizedFname = mb_strtolower(trim($fname), 'UTF-8');
    $normalizedLname = mb_strtolower(trim($lname), 'UTF-8');
    
    return hash('sha256', $normalizedFname . '|' . $normalizedLname);
}

/**
 * Buduje ranking roczny z kolumnami wydarzeń
 * 
 * Główna funkcja generująca ranking roczny. Obsługuje kwalifikację zawodników,
 * wybór najlepszych wyników oraz prezentację w formie kolumn.
 * 
 * @param int $year Rok rankingu
 * @param bool $useCache Czy używać cache
 * @return array Struktura rankingu z wydarzeniami i danymi
 */
function build_annual_ranking_with_columns(int $year, bool $useCache = true): array {
    $cacheKey = "annual_ranking_columns_{$year}";
    
    if ($useCache) {
        $cached = cache_get($cacheKey, 900); // 15 minut cache
        if ($cached !== null) {
            return $cached;
        }
    }
    
    try {
        $config = require __DIR__ . '/config.php';
        
        // Pobierz wydarzenia
        $events = fetch_events_for_year($year, $useCache);
        if (empty($events)) {
            return ['events' => [], 'data' => []];
        }
        
        // Załaduj wyniki wszystkich wydarzeń
        $allEventsResults = qr_load_all_event_results($events);
        
        // Zbierz dane uczestników
        $participantsData = qr_collect_participants_data($events, $allEventsResults);
        
        // Kolumny - wszystkie dni
        $eventDays = array_map(function($event) { return $event['day']; }, $events);
        sort($eventDays, SORT_STRING);
        
        // Przetwórz dane po klasach
        $processedData = [];
        foreach ($participantsData as $class => $participants) {
            $classResults = qr_process_class_ranking(
                $participants, 
                $eventDays, 
                $config['ranking']
            );
            
            if (!empty($classResults)) {
                $processedData[$class] = $classResults;
            }
        }
        
        $result = [
            'events' => $eventDays,
            'data' => $processedData,
            'meta' => [
                'year' => $year,
                'total_events' => count($events),
                'me_events' => count(array_filter($events, fn($e) => $e['is_me'])),
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $result, [
                'year' => $year,
                'events_count' => count($events),
            ]);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error building annual ranking for year {$year}: " . $e->getMessage());
        return ['events' => [], 'data' => []];
    }
}

/**
 * Ładuje wyniki wszystkich wydarzeń
 * 
 * @param array $events Lista wydarzeń
 * @return array Wyniki wszystkich wydarzeń
 */
function qr_load_all_event_results(array $events): array {
    $allResults = [];
    
    foreach ($events as $event) {
        $results = fetch_event_results($event['table']);
        $allResults[$event['day']] = $results;
    }
    
    return $allResults;
}

/**
 * Zbiera dane uczestników ze wszystkich wydarzeń
 * 
 * @param array $events Lista wydarzeń
 * @param array $allEventsResults Wyniki wszystkich wydarzeń
 * @return array Dane uczestników pogrupowane po klasach
 */
function qr_collect_participants_data(array $events, array $allEventsResults): array {
    $participants = [];
    
    foreach ($events as $event) {
        $day = $event['day'];
        $isME = $event['is_me'];
        $eventResults = $allEventsResults[$day] ?? [];
        
        foreach ($eventResults as $class => $classResults) {
            foreach ($classResults as $result) {
                $key = $result['key'];
                
                if (!isset($participants[$class])) {
                    $participants[$class] = [];
                }
                
                if (!isset($participants[$class][$key])) {
                    $participants[$class][$key] = [
                        'fname' => $result['fname'],
                        'lname' => $result['lname'],
                        'scores' => [],
                        'tens' => [],
                        'events' => [],
                    ];
                }
                
                $score = (float)$result['total'];
                $tens = (int)$result['tens'];
                $participants[$class][$key]['scores'][$day] = $score;
                $participants[$class][$key]['tens'][$day] = $tens;
                $participants[$class][$key]['events'][] = [
                    'day' => $day,
                    'is_me' => $isME,
                    'score' => $score,
                    'tens' => $tens,
                ];
            }
        }
    }
    
    return $participants;
}

/**
 * Przetwarza ranking dla jednej klasy
 * 
 * @param array $participants Uczestnicy klasy
 * @param array $eventDays Lista dni wydarzeń
 * @param array $rankingConfig Konfiguracja rankingu
 * @return array Przetworzone wyniki rankingu
 */
function qr_process_class_ranking(array $participants, array $eventDays, array $rankingConfig): array {
    $qualifiedParticipants = [];
    
    foreach ($participants as $participant) {
        $qualification = qr_check_qualification($participant, $rankingConfig);
        
        if ($qualification['qualified']) {
            $ranking = qr_calculate_ranking_score($participant, $qualification, $eventDays);
            $qualifiedParticipants[] = $ranking;
        }
    }
    
    if (empty($qualifiedParticipants)) {
        return [];
    }
    
    // Sortuj i przypisz rangi
    $sortedParticipants = qr_sort_and_rank_participants($qualifiedParticipants);
    
    return $sortedParticipants;
}

/**
 * Sprawdza kwalifikację uczestnika do rankingu
 * 
 * @param array $participant Dane uczestnika
 * @param array $config Konfiguracja kwalifikacji
 * @return array Wynik kwalifikacji
 */
function qr_check_qualification(array $participant, array $config): array {
    $meEvents = [];
    $otherEvents = [];
    
    // Rozdziel wydarzenia ME i inne
    foreach ($participant['events'] as $event) {
        if ($event['score'] > 0) {
            if ($event['is_me']) {
                $meEvents[] = $event;
            } else {
                $otherEvents[] = $event;
            }
        }
    }
    
    $minME = $config['min_me_events'];
    $minOther = $config['min_other_events'];
    
    $qualified = (count($meEvents) >= $minME) && (count($otherEvents) >= $minOther);
    
    return [
        'qualified' => $qualified,
        'me_events' => $meEvents,
        'other_events' => $otherEvents,
        'me_count' => count($meEvents),
        'other_count' => count($otherEvents),
    ];
}

/**
 * Oblicza wynik rankingowy uczestnika
 * 
 * @param array $participant Dane uczestnika
 * @param array $qualification Dane kwalifikacji
 * @param array $eventDays Lista dni wydarzeń
 * @return array Dane rankingowe uczestnika
 */
function qr_calculate_ranking_score(array $participant, array $qualification, array $eventDays): array {
    // Sortuj wydarzenia według wyników (najlepsze pierwsze)
    $meEvents = $qualification['me_events'];
    $otherEvents = $qualification['other_events'];
    
    usort($meEvents, fn($a, $b) => $b['score'] <=> $a['score']);
    usort($otherEvents, fn($a, $b) => $b['score'] <=> $a['score']);
    
    // Wybierz najlepsze wyniki
    $bestME = $meEvents[0];
    $best1 = $otherEvents[0];
    $best2 = $otherEvents[1];
    
    $totalScore = $bestME['score'] + $best1['score'] + $best2['score'];
    $totalTens = $bestME['tens'] + $best1['tens'] + $best2['tens'];
    
    // Przygotuj komórki dla wszystkich wydarzeń
    $cells = [];
    $selectedDays = [
        $bestME['day'] => true,
        $best1['day'] => true,
        $best2['day'] => true,
    ];
    
    foreach ($eventDays as $day) {
        $score = $participant['scores'][$day] ?? null;
        $tens = $participant['tens'][$day] ?? 0;
        $cells[$day] = [
            'score' => $score,
            'tens' => $tens,
            'selected' => ($score !== null && isset($selectedDays[$day])),
        ];
    }
    
    return [
        'fname' => $participant['fname'],
        'lname' => $participant['lname'],
        'cells' => $cells,
        'total' => $totalScore,
        'total_tens' => $totalTens,
        'me_score' => $bestME['score'],
        'best1_score' => $best1['score'],
        'best2_score' => $best2['score'],
        'total_starts' => count($qualification['me_events']) + count($qualification['other_events']),
        'me_starts' => count($qualification['me_events']),
        'other_starts' => count($qualification['other_events']),
    ];
}

/**
 * Sortuje i przypisuje rangi uczestnikom
 * 
 * @param array $participants Lista uczestników do posortowania
 * @return array Posortowani uczestnicy z rangami
 */
function qr_sort_and_rank_participants(array $participants): array {
    // Sortuj według kryteriów rankingowych
    usort($participants, function($a, $b) {
        // 1. Wynik łączny (DESC)
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        
        // 2. Suma centralnych dziesiątek (DESC)
        if ($a['total_tens'] !== $b['total_tens']) {
            return $b['total_tens'] <=> $a['total_tens'];
        }
        
        // 3. Wynik ME (DESC)
        if ($a['me_score'] !== $b['me_score']) {
            return $b['me_score'] <=> $a['me_score'];
        }
        
        // 4. Najlepszy wynik nie-ME (DESC)
        if ($a['best1_score'] !== $b['best1_score']) {
            return $b['best1_score'] <=> $a['best1_score'];
        }
        
        // 5. Drugi najlepszy wynik nie-ME (DESC)
        if ($a['best2_score'] !== $b['best2_score']) {
            return $b['best2_score'] <=> $a['best2_score'];
        }
        
        // 6. Liczba startów (DESC)
        if ($a['total_starts'] !== $b['total_starts']) {
            return $b['total_starts'] <=> $a['total_starts'];
        }
        
        // 7. Alfabetycznie
        $lastNameCmp = strcmp($a['lname'], $b['lname']);
        if ($lastNameCmp !== 0) {
            return $lastNameCmp;
        }
        
        return strcmp($a['fname'], $b['fname']);
    });
    
    // Przypisz rangi
    $rank = 1;
    $previousTotal = null;
    $previousTens = null;
    
    foreach ($participants as $index => &$participant) {
        $currentTotal = $participant['total'];
        $currentTens = $participant['total_tens'];
        
        if ($previousTotal === null || $currentTotal < $previousTotal || 
            ($currentTotal == $previousTotal && $currentTens < $previousTens)) {
            $rank = $index + 1;
        }
        
        $participant['rank'] = $rank;
        $previousTotal = $currentTotal;
        $previousTens = $currentTens;
    }
    unset($participant);
    
    return $participants;
}

/**
 * Czyści cache rankingu
 * 
 * @param int|null $year Konkretny rok (null = wszystkie)
 * @return int Liczba usuniętych wpisów cache
 */
function qr_clear_ranking_cache(?int $year = null): int {
    if ($year !== null) {
        $patterns = [
            "annual_ranking_columns_{$year}",
            "ranking_events_{$year}",
        ];
        
        $deleted = 0;
        foreach ($patterns as $pattern) {
            if (cache_delete($pattern)) {
                $deleted++;
            }
        }
        
        return $deleted;
    } else {
        // Usuń wszystkie wpisy związane z rankingiem
        return cache_cleanup();
    }
}

/**
 * Eksportuje ranking do różnych formatów
 * 
 * @param array $rankingData Dane rankingu
 * @param string $format Format eksportu ('csv', 'json', 'xml')
 * @return string Wyeksportowane dane
 */
function qr_export_ranking(array $rankingData, string $format = 'csv'): string {
    switch (strtolower($format)) {
        case 'json':
            return json_encode($rankingData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
        case 'csv':
            return qr_export_ranking_csv($rankingData);
            
        case 'xml':
            return qr_export_ranking_xml($rankingData);
            
        default:
            throw new InvalidArgumentException("Unsupported export format: {$format}");
    }
}

/**
 * Eksportuje ranking do CSV
 * 
 * @param array $rankingData Dane rankingu
 * @return string CSV content
 */
function qr_export_ranking_csv(array $rankingData): string {
    $events = $rankingData['events'] ?? [];
    $data = $rankingData['data'] ?? [];
    
    $csv = to_csv('', true); // UTF-8 BOM
    
    // Header
    $header = ['class', 'rank', 'fname', 'lname'];
    foreach ($events as $event) {
        $header[] = $event;
        $header[] = $event . '_tens';
    }
    $header[] = 'total';
    $header[] = 'total_tens';
    
    $csv .= implode(',', array_map(fn($h) => '"' . addslashes($h) . '"', $header)) . "\n";
    
    // Data rows
    foreach ($data as $classKey => $participants) {
        $className = class_map_name($classKey);
        
        foreach ($participants as $participant) {
            $row = [
                $className,
                $participant['rank'] ?? '',
                $participant['fname'] ?? '',
                $participant['lname'] ?? '',
            ];
            
            foreach ($events as $event) {
                $cellData = $participant['cells'][$event] ?? null;
                $score = ($cellData && $cellData['score'] !== null) ? (float)$cellData['score'] : '';
                $tens = ($cellData && $cellData['tens'] !== null) ? (int)$cellData['tens'] : '';
                $row[] = $score;
                $row[] = $tens;
            }
            
            $row[] = (float)($participant['total'] ?? 0);
            $row[] = (int)($participant['total_tens'] ?? 0);
            
            $csv .= implode(',', array_map(fn($r) => '"' . addslashes((string)$r) . '"', $row)) . "\n";
        }
    }
    
    return $csv;
}

/**
 * Eksportuje ranking do XML
 * 
 * @param array $rankingData Dane rankingu
 * @return string XML content
 */
function qr_export_ranking_xml(array $rankingData): string {
    $events = $rankingData['events'] ?? [];
    $data = $rankingData['data'] ?? [];
    $meta = $rankingData['meta'] ?? [];
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<ranking>' . "\n";
    
    // Meta information
    if (!empty($meta)) {
        $xml .= '  <meta>' . "\n";
        foreach ($meta as $key => $value) {
            $xml .= '    <' . htmlspecialchars($key) . '>' . htmlspecialchars((string)$value) . '</' . htmlspecialchars($key) . '>' . "\n";
        }
        $xml .= '  </meta>' . "\n";
    }
    
    // Events
    $xml .= '  <events>' . "\n";
    foreach ($events as $event) {
        $xml .= '    <event>' . htmlspecialchars($event) . '</event>' . "\n";
    }
    $xml .= '  </events>' . "\n";
    
    // Data
    $xml .= '  <classes>' . "\n";
    foreach ($data as $classKey => $participants) {
        $className = class_map_name($classKey);
        $xml .= '    <class id="' . htmlspecialchars($classKey) . '" name="' . htmlspecialchars($className) . '">' . "\n";
        
        foreach ($participants as $participant) {
            $xml .= '      <participant rank="' . htmlspecialchars((string)($participant['rank'] ?? '')) . '">' . "\n";
            $xml .= '        <fname>' . htmlspecialchars($participant['fname'] ?? '') . '</fname>' . "\n";
            $xml .= '        <lname>' . htmlspecialchars($participant['lname'] ?? '') . '</lname>' . "\n";
            $xml .= '        <total>' . htmlspecialchars((string)($participant['total'] ?? 0)) . '</total>' . "\n";
            $xml .= '        <total_tens>' . htmlspecialchars((string)($participant['total_tens'] ?? 0)) . '</total_tens>' . "\n";
            
            $xml .= '        <scores>' . "\n";
            foreach ($events as $event) {
                $cellData = $participant['cells'][$event] ?? null;
                $score = ($cellData && $cellData['score'] !== null) ? (float)$cellData['score'] : '';
                $tens = ($cellData && $cellData['tens'] !== null) ? (int)$cellData['tens'] : 0;
                $selected = ($cellData && $cellData['selected']) ? 'true' : 'false';
                
                $xml .= '          <score event="' . htmlspecialchars($event) . '" selected="' . $selected . '" tens="' . $tens . '">';
                $xml .= htmlspecialchars((string)$score) . '</score>' . "\n";
            }
            $xml .= '        </scores>' . "\n";
            
            $xml .= '      </participant>' . "\n";
        }
        
        $xml .= '    </class>' . "\n";
    }
    $xml .= '  </classes>' . "\n";
    
    $xml .= '</ranking>' . "\n";
    
    return $xml;
}
?>