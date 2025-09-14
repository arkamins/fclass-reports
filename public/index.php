<?php
/**
 * Główny plik aplikacji FClass Report
 * 
 * Unified interface obsługujący wszystkie widoki systemu:
 * - Wyniki zawodów (results)
 * - Ranking roczny (ranking) 
 * - Zespoły ME (teams)
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

// Ustaw kodowanie odpowiedzi
header('Content-Type: text/html; charset=utf-8');

// === BEZPIECZEŃSTWO I WALIDACJA ===
session_start();

// Podstawowe zabezpieczenia
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP'])) {
    // Log potential proxy usage for monitoring
    error_log("Proxy usage detected: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

// Rate limiting (prosty mechanizm)
$clientKey = 'requests_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!isset($_SESSION[$clientKey])) {
    $_SESSION[$clientKey] = ['count' => 0, 'last_reset' => time()];
}

$rateLimit = &$_SESSION[$clientKey];
if ((time() - $rateLimit['last_reset']) > 60) {
    // Reset co minutę
    $rateLimit['count'] = 0;
    $rateLimit['last_reset'] = time();
}

$rateLimit['count']++;
if ($rateLimit['count'] > 100) { // 100 requestów na minutę
    http_response_code(429);
    die('Rate limit exceeded. Please try again later.');
}

// === INCLUDE DEPENDENCIES ===
require_once __DIR__ . '/../app/encoding.php';
require_once __DIR__ . '/../app/classmap.php';
require_once __DIR__ . '/../app/cache.php';

// === KONFIGURACJA I STAŁE ===
$config = require __DIR__ . '/../app/config.php';

// Walidacja parametrów wejściowych
$view = sanitize_input($_GET['view'] ?? 'results', ['max_length' => 20]);
$allowedViews = ['results', 'ranking', 'teams'];

if (!in_array($view, $allowedViews, true)) {
    $view = 'results';
}

/**
 * Renderuje nawigację aplikacji
 * 
 * @param string $activeView Aktywny widok
 * @return void
 */
function render_navigation(string $activeView): void {
    $navItems = [
        'ranking' => ['label' => 'Ranking', 'icon' => '🏆'],
        'results' => ['label' => 'Wyniki', 'icon' => '📊'],
        'teams' => ['label' => 'Zespoły', 'icon' => '👥'],
    ];
    
    echo '<nav class="d-flex gap-2 mb-3" role="navigation" aria-label="Główna nawigacja">';
    
    foreach ($navItems as $viewKey => $item) {
        $isActive = ($viewKey === $activeView);
        $btnClass = $isActive ? 'btn-primary' : 'btn-outline-secondary';
        $ariaCurrent = $isActive ? ' aria-current="page"' : '';
        
        echo '<a class="btn ' . $btnClass . '"' . $ariaCurrent . ' href="?view=' . urlencode($viewKey) . '">';
        echo '<span class="me-1">' . $item['icon'] . '</span>';
        echo e($item['label']);
        echo '</a>';
    }
    
    echo '</nav>';
}

/**
 * Renderuje breadcrumb nawigację
 * 
 * @param array $breadcrumbs Lista breadcrumbs
 * @return void
 */
function render_breadcrumbs(array $breadcrumbs): void {
    if (empty($breadcrumbs)) return;
    
    echo '<nav aria-label="breadcrumb">';
    echo '<ol class="breadcrumb">';
    
    $lastIndex = count($breadcrumbs) - 1;
    foreach ($breadcrumbs as $index => $crumb) {
        $isLast = ($index === $lastIndex);
        
        echo '<li class="breadcrumb-item' . ($isLast ? ' active' : '') . '">';
        
        if ($isLast) {
            echo e($crumb['title']);
        } else {
            echo '<a href="' . e($crumb['url']) . '">' . e($crumb['title']) . '</a>';
        }
        
        echo '</li>';
    }
    
    echo '</ol>';
    echo '</nav>';
}

/**
 * Waliduje i sanityzuje parametry roku
 * 
 * @param mixed $yearParam Parametr roku z GET
 * @param int $maxYear Maksymalny dozwolony rok
 * @return int Zwalidowany rok
 */
function validate_year_parameter($yearParam, int $maxYear): int {
    $year = (int)($yearParam ?? $maxYear);
    $minYear = $GLOBALS['config']['app']['min_year'];
    
    if ($year < $minYear) {
        $year = $minYear;
    } elseif ($year > $maxYear) {
        $year = $maxYear;
    }
    
    return $year;
}

/**
 * Renderuje sekcję statystyk (dla debug/monitoring)
 * 
 * @param array $stats Statystyki do wyświetlenia
 * @return void
 */
function render_debug_stats(array $stats): void {
    global $config;
    
    if (!$config['app']['allow_debug']) {
        return;
    }
    
    echo '<details class="mt-4">';
    echo '<summary class="text-muted small">Debug Info</summary>';
    echo '<pre class="small text-muted mt-2">';
    echo htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '</pre>';
    echo '</details>';
}

// === ROUTING LOGIC ===

try {
    switch ($view) {
        case 'teams':
            require_once __DIR__ . '/views/teams_view.php';
            render_teams_view();
            break;
            
        case 'ranking':
            require_once __DIR__ . '/views/ranking_view.php';
            render_ranking_view();
            break;
            
        case 'results':
        default:
            require_once __DIR__ . '/views/results_view.php';
            render_results_view();
            break;
    }
    
} catch (Exception $e) {
    // Log błędu
    error_log("Application error in view '{$view}': " . $e->getMessage());
    
    // Pokaż przyjazny błąd użytkownikowi
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <title>Błąd - <?php echo e($config['app']['name']); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
    <div class="container py-4">
        <div class="alert alert-danger" role="alert">
            <h4 class="alert-heading">Wystąpił błąd</h4>
            <p>Przepraszamy, wystąpił nieoczekiwany błąd. Spróbuj ponownie za chwilę.</p>
            <hr>
            <p class="mb-0">
                <a href="?" class="btn btn-primary">Powrót do strony głównej</a>
                <?php if ($config['app']['allow_debug']): ?>
                    <small class="text-muted d-block mt-2">Debug: <?php echo e($e->getMessage()); ?></small>
                <?php endif; ?>
            </p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// === DODATKOWE FUNKCJE POMOCNICZE ===

/**
 * Generuje link sortowania dla kolumn tabeli
 * 
 * @param string $label Etykieta kolumny
 * @param string $sortKey Klucz sortowania
 * @param string $currentSort Aktualnie wybrane sortowanie
 * @param string $currentDir Aktualny kierunek sortowania
 * @param array $additionalParams Dodatkowe parametry URL
 * @return string HTML link
 */
function generate_sort_link(string $label, string $sortKey, string $currentSort, string $currentDir, array $additionalParams = []): string {
    // Określ następny kierunek sortowania
    $nextDirection = ($currentSort === $sortKey && $currentDir === 'desc') ? 'asc' : 'desc';
    
    // Ikona kierunku
    $icon = '';
    if ($currentSort === $sortKey) {
        $icon = $currentDir === 'desc' ? ' ↓' : ' ↑';
    }
    
    // Buduj URL
    $params = array_merge($additionalParams, [
        'sort' => $sortKey,
        'dir' => $nextDirection
    ]);
    
    $url = '?' . http_build_query($params);
    
    return '<a href="' . e($url) . '" class="text-decoration-none text-reset" title="Sortuj według: ' . e($label) . '">' . 
           e($label) . '<span class="text-muted">' . $icon . '</span></a>';
}

/**
 * Formatuje liczbę dla wyświetlania
 * 
 * @param float|int|null $number Liczba do formatowania
 * @param int $decimals Liczba miejsc po przecinku
 * @param string $nullDisplay Co wyświetlić dla null
 * @return string Sformatowana liczba
 */
function format_display_number($number, int $decimals = 0, string $nullDisplay = '–'): string {
    if ($number === null || $number === '') {
        return $nullDisplay;
    }
    
    return format_number((float)$number, $decimals, true);
}

/**
 * Generuje unikalne ID dla elementów HTML
 * 
 * @param string $prefix Prefix ID
 * @return string Unikalne ID
 */
function generate_element_id(string $prefix = 'elem'): string {
    static $counter = 0;
    return $prefix . '_' . (++$counter) . '_' . substr(md5(uniqid()), 0, 6);
}

/**
 * Renderuje paginację (dla przyszłego użytku)
 * 
 * @param int $currentPage Aktualna strona
 * @param int $totalPages Łączna liczba stron
 * @param string $baseUrl Bazowy URL
 * @return void
 */
function render_pagination(int $currentPage, int $totalPages, string $baseUrl): void {
    if ($totalPages <= 1) return;
    
    echo '<nav aria-label="Paginacja wyników">';
    echo '<ul class="pagination justify-content-center">';
    
    // Poprzednia strona
    $prevDisabled = ($currentPage <= 1) ? ' disabled' : '';
    $prevPage = max(1, $currentPage - 1);
    echo '<li class="page-item' . $prevDisabled . '">';
    echo '<a class="page-link" href="' . e($baseUrl . '&page=' . $prevPage) . '">Poprzednia</a>';
    echo '</li>';
    
    // Strony
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    for ($page = $startPage; $page <= $endPage; $page++) {
        $active = ($page === $currentPage) ? ' active' : '';
        echo '<li class="page-item' . $active . '">';
        echo '<a class="page-link" href="' . e($baseUrl . '&page=' . $page) . '">' . $page . '</a>';
        echo '</li>';
    }
    
    // Następna strona
    $nextDisabled = ($currentPage >= $totalPages) ? ' disabled' : '';
    $nextPage = min($totalPages, $currentPage + 1);
    echo '<li class="page-item' . $nextDisabled . '">';
    echo '<a class="page-link" href="' . e($baseUrl . '&page=' . $nextPage) . '">Następna</a>';
    echo '</li>';
    
    echo '</ul>';
    echo '</nav>';
}

/**
 * Renderuje export buttons
 * 
 * @param string $baseExportUrl Bazowy URL eksportu
 * @param array $params Parametry do przekazania
 * @return void
 */
function render_export_buttons(string $baseExportUrl, array $params = []): void {
    $csvUrl = $baseExportUrl . '?' . http_build_query(array_merge($params, ['fmt' => 'csv']));
    $jsonUrl = $baseExportUrl . '?' . http_build_query(array_merge($params, ['fmt' => 'json']));
    
    echo '<div class="btn-group" role="group" aria-label="Opcje eksportu">';
    echo '<a class="btn btn-outline-secondary btn-sm" href="' . e($csvUrl) . '" title="Eksport do pliku CSV">';
    echo '<i class="bi bi-file-earmark-spreadsheet"></i> CSV';
    echo '</a>';
    echo '<a class="btn btn-outline-secondary btn-sm" href="' . e($jsonUrl) . '" title="Eksport do formatu JSON">';
    echo '<i class="bi bi-file-earmark-code"></i> JSON';
    echo '</a>';
    echo '</div>';
}

/**
 * Renderuje komunikat o braku danych
 * 
 * @param string $message Wiadomość do wyświetlenia
 * @param string $type Typ alertu Bootstrap
 * @return void
 */
function render_no_data_message(string $message = 'Brak danych do wyświetlenia', string $type = 'info'): void {
    echo '<div class="alert alert-' . e($type) . ' text-center" role="alert">';
    echo '<i class="bi bi-info-circle me-2"></i>';
    echo e($message);
    echo '</div>';
}

/**
 * Renderuje footer aplikacji
 * 
 * @param array $additionalInfo Dodatkowe informacje do wyświetlenia
 * @return void
 */
function render_footer(array $additionalInfo = []): void {
    global $config;
    
    echo '<footer class="border-top pt-3 mt-5">';
    echo '<div class="row">';
    
    // Informacje podstawowe
    echo '<div class="col-md-6">';
    echo '<p class="text-muted small mb-1">';
    echo '<strong>' . e($config['app']['name']) . '</strong> v' . e($config['app']['version']);
    echo '</p>';
    echo '<p class="text-muted small mb-0">';
    echo 'Wygenerowano: ' . format_date(time(), 'Y-m-d H:i:s') . ' (' . e($config['app']['timezone']) . ')';
    echo '</p>';
    echo '</div>';
    
    // Dodatkowe informacje
    if (!empty($additionalInfo)) {
        echo '<div class="col-md-6 text-md-end">';
        foreach ($additionalInfo as $key => $value) {
            echo '<p class="text-muted small mb-1">';
            echo '<strong>' . e($key) . ':</strong> ' . e($value);
            echo '</p>';
        }
        echo '</div>';
    }
    
    echo '</div>';
    
    // Cache info w trybie debug
    if ($config['app']['allow_debug']) {
        $cacheStats = cache_stats();
        echo '<div class="row mt-2">';
        echo '<div class="col-12">';
        echo '<details class="small">';
        echo '<summary class="text-muted">Cache Stats</summary>';
        echo '<dl class="row small mt-2">';
        echo '<dt class="col-sm-3">Status:</dt><dd class="col-sm-9">' . ($cacheStats['enabled'] ? 'Włączony' : 'Wyłączony') . '</dd>';
        echo '<dt class="col-sm-3">Pliki:</dt><dd class="col-sm-9">' . number_format($cacheStats['files']) . '</dd>';
        echo '<dt class="col-sm-3">Rozmiar:</dt><dd class="col-sm-9">' . formatBytes($cacheStats['total_size']) . '</dd>';
        echo '</dl>';
        echo '</details>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</footer>';
}

/**
 * Formatuje rozmiar w bajtach
 * 
 * @param int $bytes Rozmiar w bajtach
 * @param int $precision Precyzja
 * @return string Sformatowany rozmiar
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Renderuje loading placeholder
 * 
 * @param string $message Wiadomość ładowania
 * @return void
 */
function render_loading_placeholder(string $message = 'Ładowanie...'): void {
    echo '<div class="d-flex justify-content-center align-items-center py-5">';
    echo '<div class="spinner-border text-primary me-3" role="status" aria-hidden="true"></div>';
    echo '<span>' . e($message) . '</span>';
    echo '</div>';
}

/**
 * Sprawdza czy request jest AJAX
 * 
 * @return bool True jeśli AJAX
 */
function is_ajax_request(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Renderuje JSON response dla AJAX
 * 
 * @param mixed $data Dane do zwrócenia
 * @param int $statusCode Kod statusu HTTP
 * @return void
 */
function render_json_response($data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'error',
        'data' => $data,
        'timestamp' => time(),
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Sprawdź czy to request AJAX i obsłuż odpowiednio
if (is_ajax_request()) {
    // Dla AJAX zwróć tylko dane bez HTML layout
    try {
        $data = [];
        
        switch ($view) {
            case 'teams':
                require_once __DIR__ . '/../app/teams.php';
                $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
                $data = t_fetch_teams_by_class($day);
                break;
                
            case 'ranking':
                require_once __DIR__ . '/../app/query_ranking.php';
                $year = validate_year_parameter($_GET['year'], fetch_max_year());
                $data = build_annual_ranking_with_columns($year);
                break;
                
            case 'results':
                require_once __DIR__ . '/../app/event_results.php';
                $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
                $sort = sanitize_input($_GET['sort'] ?? 'rank', ['max_length' => 20]);
                $dir = sanitize_input($_GET['dir'] ?? 'asc', ['max_length' => 4]);
                $data = er_build_event_tables($day, $sort, $dir);
                break;
        }
        
        render_json_response($data);
        
    } catch (Exception $e) {
        render_json_response(['error' => $e->getMessage()], 500);
    }
}
?>