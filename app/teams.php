<?php
/**
 * Zarządzanie zespołami zawodów strzeleckich
 * 
 * Obsługuje zespoły dla Mistrzostw Europy (ME) z rankingiem,
 * walidacją danych oraz eksportem. Zintegrowane z nowym systemem cache i bezpieczeństwa.
 * 
 * @author FClass Report Team
 * @version 9.1.0
 * @since 2025
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/encoding.php';
require_once __DIR__ . '/classmap.php';

/**
 * Waliduje i normalizuje dzień wydarzenia
 * 
 * @param string $day Dzień w formacie YYYYMMDD
 * @return string|null Znormalizowany dzień lub null jeśli niepoprawny
 */
function t_normalize_day(string $day): ?string {
    require_once __DIR__ . '/event_results.php';
    return er_normalize_day_table($day);
}

/**
 * Pobiera maksymalny rok z dostępnych danych
 * 
 * @param bool $useCache Czy używać cache
 * @return int Maksymalny dostępny rok
 */
function t_fetch_max_year(bool $useCache = true): int {
    require_once __DIR__ . '/event_results.php';
    return er_fetch_max_year($useCache);
}

/**
 * Pobiera wydarzenia ME dla danego roku
 * 
 * Zwraca tylko wydarzenia oznaczone jako Mistrzostwa Europy
 * z zastosowaniem filtrów wykluczających.
 * 
 * @param int $year Rok do wyszukania
 * @param bool $useCache Czy używać cache
 * @return array Lista wydarzeń ME
 */
function t_fetch_me_events_for_year(int $year, bool $useCache = true): array {
    $cacheKey = "teams_me_events_{$year}";
    
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
        $params = [':min' => $min, ':max' => $max, ':me_phrase' => '%' . $mePhrase . '%'];
        
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
              AND UPPER(opis) LIKE :me_phrase
              {$excludeClause}
            ORDER BY data ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        
        $events = [];
        foreach ($rows as $row) {
            $day = (string)$row['data'];
            $normalizedDay = t_normalize_day($day);
            
            if ($normalizedDay === null) {
                continue;
            }
            
            // Sprawdź czy tabela istnieje
            if (!tableExists($normalizedDay)) {
                continue;
            }
            
            $events[] = [
                'day' => $normalizedDay,
                'opis' => clean_for_db($row['opis']),
                'year' => (int)substr($normalizedDay, 0, 4),
                'formatted_date' => t_format_event_date($normalizedDay),
            ];
        }
        
        // Zapisz w cache
        if ($useCache) {
            cache_set($cacheKey, $events);
        }
        
        return $events;
        
    } catch (Exception $e) {
        error_log("Error fetching ME events for year {$year}: " . $e->getMessage());
        return [];
    }
}

/**
 * Formatuje datę wydarzenia do czytelnej formy
 * 
 * @param string $day Dzień w formacie YYYYMMDD
 * @return string Sformatowana data
 */
function t_format_event_date(string $day): string {
    if (strlen($day) !== 8) {
        return $day;
    }
    
    $year = substr($day, 0, 4);
    $month = substr($day, 4, 2);
    $dayNum = substr($day, 6, 2);
    
    $monthNames = [
        '01' => 'Styczeń', '02' => 'Luty', '03' => 'Marzec', '04' => 'Kwiecień',
        '05' => 'Maj', '06' => 'Czerwiec', '07' => 'Lipiec', '08' => 'Sierpień',
        '09' => 'Wrzesień', '10' => 'Październik', '11' => 'Listopad', '12' => 'Grudzień'
    ];
    
    $monthName = $monthNames[$month] ?? $month;
    return "{$dayNum} {$monthName} {$year}";
}

/**
 * Pobiera zespoły pogrupowane po klasach dla danego dnia ME
 * 
 * Główna funkcja pobierająca i przetwarzająca dane zespołów z rankingiem.
 * UWAGA: Ta funkcja pobiera też dane o centralnych dziesiątkach!
 * 
 * @param string $day Dzień wydarzenia w formacie YYYYMMDD
 * @param bool $useCache Czy używać cache
 * @return array Zespoły pogrupowane po klasach [class_id => ['class_name' => string, 'teams' => array]]
 */
function t_fetch_teams_by_class(string $day, bool $useCache = true): array {
    $normalizedDay = t_normalize_day($day);
    if ($normalizedDay === null) {
        return [];
    }
    
    $cacheKey = "teams_by_class_{$normalizedDay}";
    
    if ($useCache) {
        $cached = cache_get($cacheKey, 600); // 10 minut cache
        if ($cached !== null) {
            return $cached;
        }
    }
    
    try {
        $rawTeams = t_fetch_raw_teams_data($normalizedDay);
        $processedTeams = t_process_teams_data($rawTeams);
        
        // Zapisz w cache
        if ($useCache && !empty($processedTeams)) {
            cache_set($cacheKey, $processedTeams, [
                'day' => $normalizedDay,
                'teams_count' => array_sum(array_map(fn($class) => count($class['teams']), $processedTeams)),
            ]);
        }
        
        return $processedTeams;
        
    } catch (Exception $e) {
        error_log("Error fetching teams for day {$day}: " . $e->getMessage());
        return [];
    }
}

/**
 * Pobiera surowe dane zespołów z bazy danych
 * POPRAWIONE: Dodane pobieranie centralnych dziesiątek (x_d1, x_d2, x_d3)
 * 
 * @param string $day Znormalizowany dzień wydarzenia
 * @return array Surowe dane zespołów
 */
function t_fetch_raw_teams_data(string $day): array {
    if (!validateTableName($day)) {
        throw new InvalidArgumentException("Invalid table name: {$day}");
    }
    
    if (!tableExists($day)) {
        throw new RuntimeException("Event table does not exist: {$day}");
    }
    
    $pdo = db();
    
    // POPRAWIONE ZAPYTANIE - dodane sumy centralnych dziesiątek
    $sql = "
        SELECT
            t.tclass       AS class_id,
            t.team_name    AS team_name,
            t.id1          AS member1_id,
            r1.fname       AS member1_fname,
            r1.lname       AS member1_lname,
            (COALESCE(r1.res_d1,0) + COALESCE(r1.res_d2,0) + COALESCE(r1.res_d3,0)) AS member1_total,
            (COALESCE(r1.x_d1,0) + COALESCE(r1.x_d2,0) + COALESCE(r1.x_d3,0)) AS member1_tens,
            t.id2          AS member2_id,
            r2.fname       AS member2_fname,
            r2.lname       AS member2_lname,
            (COALESCE(r2.res_d1,0) + COALESCE(r2.res_d2,0) + COALESCE(r2.res_d3,0)) AS member2_total,
            (COALESCE(r2.x_d1,0) + COALESCE(r2.x_d2,0) + COALESCE(r2.x_d3,0)) AS member2_tens
        FROM teams t
        LEFT JOIN `{$day}` r1 ON r1.id = t.id1 AND r1.class = t.tclass
        LEFT JOIN `{$day}` r2 ON r2.id = t.id2 AND r2.class = t.tclass
        WHERE t.tdday = :day
        ORDER BY t.tclass, t.team_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':day' => $day]);
    $rows = $stmt->fetchAll();
    
    // Oczyść i waliduj dane
    return array_map('clean_for_db', $rows);
}

/**
 * Przetwarza surowe dane zespołów
 * 
 * @param array $rawTeams Surowe dane zespołów
 * @return array Przetworzone zespoły z rankingiem
 */
function t_process_teams_data(array $rawTeams): array {
    $config = require __DIR__ . '/config.php';
    $teamsByClass = [];
    
    // Grupuj po klasach
    foreach ($rawTeams as $row) {
        $classId = (string)($row['class_id'] ?? '');
        if ($classId === '') {
            continue;
        }
        
        if (!isset($teamsByClass[$classId])) {
            $teamsByClass[$classId] = [
                'class_name' => class_map_name($classId),
                'teams' => [],
            ];
        }
        
        $team = t_build_team_data($row);
        if ($team !== null) {
            $teamsByClass[$classId]['teams'][] = $team;
        }
    }
    
    // Sortuj klasy i przypisz rangi
    $sortedClasses = sort_classes(array_keys($teamsByClass), 'numeric');
    $result = [];
    
    foreach ($sortedClasses as $classId) {
        $classData = $teamsByClass[$classId];
        
        // Sortuj zespoły i przypisz rangi
        $sortedTeams = t_sort_and_rank_teams($classData['teams']);
        
        $result[$classId] = [
            'class_name' => $classData['class_name'],
            'teams' => $sortedTeams,
        ];
    }
    
    return $result;
}

/**
 * Buduje dane pojedynczego zespołu
 * POPRAWIONE: Dodane obsługa centralnych dziesiątek
 * 
 * @param array $row Wiersz danych z bazy
 * @return array|null Dane zespołu lub null jeśli niepoprawne
 */
function t_build_team_data(array $row): ?array {
    $teamName = trim((string)($row['team_name'] ?? ''));
    if ($teamName === '') {
        return null;
    }
    
    $member1Total = (float)($row['member1_total'] ?? 0);
    $member2Total = (float)($row['member2_total'] ?? 0);
    $member1Tens = (int)($row['member1_tens'] ?? 0);  // DODANE
    $member2Tens = (int)($row['member2_tens'] ?? 0);  // DODANE
    
    $teamTotal = $member1Total + $member2Total;
    $teamTens = $member1Tens + $member2Tens;  // DODANE - suma X zespołu
    
    // Waliduj członków zespołu
    $member1 = t_validate_team_member([
        'id' => (string)($row['member1_id'] ?? ''),
        'fname' => trim((string)($row['member1_fname'] ?? '')),
        'lname' => trim((string)($row['member1_lname'] ?? '')),
        'total' => $member1Total,
        'tens' => $member1Tens,  // DODANE
    ]);
    
    $member2 = t_validate_team_member([
        'id' => (string)($row['member2_id'] ?? ''),
        'fname' => trim((string)($row['member2_fname'] ?? '')),
        'lname' => trim((string)($row['member2_lname'] ?? '')),
        'total' => $member2Total,
        'tens' => $member2Tens,  // DODANE
    ]);
    
    return [
        'team_name' => $teamName,
        'member1' => $member1,
        'member2' => $member2,
        'team_total' => $teamTotal,
        'team_tens' => $teamTens,  // DODANE
        'valid_team' => ($member1['valid'] && $member2['valid']),
    ];
}

/**
 * Waliduje dane członka zespołu
 * POPRAWIONE: Dodane pole tens
 * 
 * @param array $memberData Dane członka
 * @return array Zwalidowane dane członka
 */
function t_validate_team_member(array $memberData): array {
    $config = require __DIR__ . '/config.php';
    $namePattern = $config['constants']['name_pattern'];
    
    $fname = $memberData['fname'] ?? '';
    $lname = $memberData['lname'] ?? '';
    $fullName = trim($fname . ' ' . $lname);
    
    // Waliduj imię i nazwisko
    $validName = !empty($fullName) && 
                 preg_match($namePattern, $fname) && 
                 preg_match($namePattern, $lname);
    
    return [
        'id' => $memberData['id'] ?? '',
        'fname' => $fname,
        'lname' => $lname,
        'full_name' => $fullName,
        'total' => (float)($memberData['total'] ?? 0),
        'tens' => (int)($memberData['tens'] ?? 0),  // DODANE
        'valid' => $validName && ($memberData['total'] ?? 0) >= 0,
    ];
}

/**
 * Sortuje zespoły i przypisuje rangi
 * POPRAWIONE: Uwzględnia centralne dziesiątki w sortowaniu
 * 
 * @param array $teams Lista zespołów
 * @return array Posortowane zespoły z rangami
 */
function t_sort_and_rank_teams(array $teams): array {
    if (empty($teams)) {
        return [];
    }
    
    // Sortuj według wyników zespołu z uwzględnieniem centralnych dziesiątek
    usort($teams, function($a, $b) {
        // 1. Wynik łączny zespołu (DESC)
        if ($a['team_total'] !== $b['team_total']) {
            return $b['team_total'] <=> $a['team_total'];
        }
        
        // 2. Suma centralnych dziesiątek zespołu (DESC) - DODANE
        if (($a['team_tens'] ?? 0) !== ($b['team_tens'] ?? 0)) {
            return ($b['team_tens'] ?? 0) <=> ($a['team_tens'] ?? 0);
        }
        
        // 3. Wynik lepszego zawodnika (DESC)
        $aBest = max($a['member1']['total'], $a['member2']['total']);
        $bBest = max($b['member1']['total'], $b['member2']['total']);
        
        if ($aBest !== $bBest) {
            return $bBest <=> $aBest;
        }
        
        // 4. Centralne dziesiątki lepszego zawodnika (DESC) - DODANE
        $aBestTens = ($a['member1']['total'] > $a['member2']['total']) ? 
                      $a['member1']['tens'] : $a['member2']['tens'];
        $bBestTens = ($b['member1']['total'] > $b['member2']['total']) ? 
                      $b['member1']['tens'] : $b['member2']['tens'];
        
        if ($aBestTens !== $bBestTens) {
            return $bBestTens <=> $aBestTens;
        }
        
        // 5. Wynik drugiego zawodnika (DESC)
        $aSecond = min($a['member1']['total'], $a['member2']['total']);
        $bSecond = min($b['member1']['total'], $b['member2']['total']);
        
        if ($aSecond !== $bSecond) {
            return $bSecond <=> $aSecond;
        }
        
        // 6. Alfabetycznie po nazwie zespołu
        return strcmp($a['team_name'], $b['team_name']);
    });
    
    // Przypisz rangi
    $rank = 1;
    $previousSignature = null;
    
    foreach ($teams as $index => &$team) {
        $signature = json_encode([
            $team['team_total'],
            $team['team_tens'] ?? 0,  // DODANE
            max($team['member1']['total'], $team['member2']['total']),
            min($team['member1']['total'], $team['member2']['total']),
        ]);
        
        if ($previousSignature === null || $signature !== $previousSignature) {
            $rank = $index + 1;
        }
        
        $team['rank'] = $rank;
        $previousSignature = $signature;
    }
    unset($team);
    
    return $teams;
}

/**
 * Pobiera płaską listę zespołów dla eksportu
 * 
 * @param string $day Dzień wydarzenia
 * @param bool $useCache Czy używać cache
 * @return array Płaska lista zespołów
 */
function t_fetch_teams_flat(string $day, bool $useCache = true): array {
    $teamsByClass = t_fetch_teams_by_class($day, $useCache);
    
    $flatTeams = [];
    foreach ($teamsByClass as $classId => $classData) {
        foreach ($classData['teams'] as $team) {
            $flatTeam = [
                'class_id' => $classId,
                'class_name' => $classData['class_name'],
                'rank' => $team['rank'] ?? 0,
                'team_name' => $team['team_name'] ?? '',
                'team_total' => $team['team_total'] ?? 0,
                'team_tens' => $team['team_tens'] ?? 0,  // DODANE
                'valid_team' => $team['valid_team'] ?? false,
                
                // Członek 1
                'member1_id' => $team['member1']['id'] ?? '',
                'member1_fname' => $team['member1']['fname'] ?? '',
                'member1_lname' => $team['member1']['lname'] ?? '',
                'member1_full_name' => $team['member1']['full_name'] ?? '',
                'member1_total' => $team['member1']['total'] ?? 0,
                'member1_tens' => $team['member1']['tens'] ?? 0,  // DODANE
                'member1_valid' => $team['member1']['valid'] ?? false,
                
                // Członek 2
                'member2_id' => $team['member2']['id'] ?? '',
                'member2_fname' => $team['member2']['fname'] ?? '',
                'member2_lname' => $team['member2']['lname'] ?? '',
                'member2_full_name' => $team['member2']['full_name'] ?? '',
                'member2_total' => $team['member2']['total'] ?? 0,
                'member2_tens' => $team['member2']['tens'] ?? 0,  // DODANE
                'member2_valid' => $team['member2']['valid'] ?? false,
            ];
            
            $flatTeams[] = $flatTeam;
        }
    }
    
    return $flatTeams;
}

/**
 * Pobiera statystyki zespołów dla danego wydarzenia
 * 
 * @param string $day Dzień wydarzenia
 * @return array Statystyki zespołów
 */
function t_get_teams_statistics(string $day): array {
    $teamsByClass = t_fetch_teams_by_class($day);
    
    $stats = [
        'total_classes' => count($teamsByClass),
        'total_teams' => 0,
        'valid_teams' => 0,
        'total_participants' => 0,
        'total_tens' => 0,  // DODANE
        'classes_breakdown' => [],
        'score_ranges' => [
            'min' => null,
            'max' => null,
            'average' => 0,
        ],
    ];
    
    $allScores = [];
    $allTens = [];  // DODANE
    
    foreach ($teamsByClass as $classId => $classData) {
        $teamsCount = count($classData['teams']);
        $validTeamsCount = 0;
        $classScores = [];
        $classTens = [];  // DODANE
        
        foreach ($classData['teams'] as $team) {
            $stats['total_teams']++;
            $stats['total_participants'] += 2; // Każdy zespół ma 2 członków
            
            if ($team['valid_team']) {
                $validTeamsCount++;
                $stats['valid_teams']++;
            }
            
            $teamScore = $team['team_total'];
            $teamTens = $team['team_tens'] ?? 0;  // DODANE
            
            $classScores[] = $teamScore;
            $allScores[] = $teamScore;
            
            $classTens[] = $teamTens;  // DODANE
            $allTens[] = $teamTens;  // DODANE
            $stats['total_tens'] += $teamTens;  // DODANE
        }
        
        $stats['classes_breakdown'][$classId] = [
            'name' => $classData['class_name'],
            'teams' => $teamsCount,
            'valid_teams' => $validTeamsCount,
            'min_score' => !empty($classScores) ? min($classScores) : 0,
            'max_score' => !empty($classScores) ? max($classScores) : 0,
            'avg_score' => !empty($classScores) ? round(array_sum($classScores) / count($classScores), 1) : 0,
            'total_tens' => array_sum($classTens),  // DODANE
            'avg_tens' => !empty($classTens) ? round(array_sum($classTens) / count($classTens), 1) : 0,  // DODANE
        ];
    }
    
    // Oblicz statystyki globalne
    if (!empty($allScores)) {
        $stats['score_ranges']['min'] = min($allScores);
        $stats['score_ranges']['max'] = max($allScores);
        $stats['score_ranges']['average'] = round(array_sum($allScores) / count($allScores), 1);
    }
    
    return $stats;
}

/**
 * Wyszukuje zespoły według kryteriów
 * 
 * @param string $day Dzień wydarzenia
 * @param array $criteria Kryteria wyszukiwania
 * @return array Znalezione zespoły
 */
function t_search_teams(string $day, array $criteria = []): array {
    $allTeams = t_fetch_teams_flat($day);
    
    if (empty($criteria)) {
        return $allTeams;
    }
    
    $filtered = array_filter($allTeams, function($team) use ($criteria) {
        // Filtr po klasie
        if (!empty($criteria['class']) && $team['class_id'] !== $criteria['class']) {
            return false;
        }
        
        // Filtr po nazwie zespołu
        if (!empty($criteria['team_name'])) {
            $searchTerm = mb_strtolower($criteria['team_name'], 'UTF-8');
            $teamName = mb_strtolower($team['team_name'], 'UTF-8');
            if (mb_strpos($teamName, $searchTerm, 0, 'UTF-8') === false) {
                return false;
            }
        }
        
        // Filtr po nazwisku członka
        if (!empty($criteria['member_name'])) {
            $searchTerm = mb_strtolower($criteria['member_name'], 'UTF-8');
            $member1Name = mb_strtolower($team['member1_full_name'], 'UTF-8');
            $member2Name = mb_strtolower($team['member2_full_name'], 'UTF-8');
            
            if (mb_strpos($member1Name, $searchTerm, 0, 'UTF-8') === false &&
                mb_strpos($member2Name, $searchTerm, 0, 'UTF-8') === false) {
                return false;
            }
        }
        
        // Filtr po minimum punktów
        if (isset($criteria['min_score']) && $team['team_total'] < $criteria['min_score']) {
            return false;
        }
        
        // Filtr po maksimum punktów
        if (isset($criteria['max_score']) && $team['team_total'] > $criteria['max_score']) {
            return false;
        }
        
        // Filtr po miejscu
        if (isset($criteria['max_rank']) && $team['rank'] > $criteria['max_rank']) {
            return false;
        }
        
        // Filtr po minimum centralnych dziesiątek - DODANE
        if (isset($criteria['min_tens']) && $team['team_tens'] < $criteria['min_tens']) {
            return false;
        }
        
        return true;
    });
    
    return array_values($filtered);
}

/**
 * Czyści cache zespołów
 * 
 * @param string|null $day Konkretny dzień (null = wszystkie)
 * @return int Liczba usuniętych wpisów cache
 */
function t_clear_teams_cache(?string $day = null): int {
    if ($day !== null) {
        $normalizedDay = t_normalize_day($day);
        if ($normalizedDay === null) {
            return 0;
        }
        
        $patterns = [
            "teams_by_class_{$normalizedDay}",
            "teams_me_events_" . substr($normalizedDay, 0, 4),
        ];
        
        $deleted = 0;
        foreach ($patterns as $pattern) {
            if (cache_delete($pattern)) {
                $deleted++;
            }
        }
        
        return $deleted;
    } else {
        // Usuń wszystkie wpisy związane z zespołami
        return cache_cleanup();
    }
}

/**
 * Waliduje konfigurację zespołów
 * 
 * @return array Raport walidacji
 */
function t_validate_teams_config(): array {
    $config = require __DIR__ . '/config.php';
    $teamsConfig = $config['teams'] ?? [];
    
    $report = [
        'valid' => true,
        'issues' => [],
        'config' => $teamsConfig,
    ];
    
    // Sprawdź wymagane ustawienia
    $requiredSettings = ['min_members', 'max_members', 'only_me_events'];
    foreach ($requiredSettings as $setting) {
        if (!isset($teamsConfig[$setting])) {
            $report['issues'][] = "Missing teams config setting: {$setting}";
            $report['valid'] = false;
        }
    }
    
    // Waliduj wartości
    if (isset($teamsConfig['min_members']) && $teamsConfig['min_members'] < 1) {
        $report['issues'][] = "min_members must be >= 1";
        $report['valid'] = false;
    }
    
    if (isset($teamsConfig['max_members']) && $teamsConfig['max_members'] < $teamsConfig['min_members']) {
        $report['issues'][] = "max_members must be >= min_members";
        $report['valid'] = false;
    }
    
    return $report;
}
?>