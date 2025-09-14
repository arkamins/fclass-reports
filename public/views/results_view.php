<?php
/**
 * Widok wyników zawodów
 * 
 * Renderuje interfejs do przeglądania wyników konkretnych zawodów
 * z obsługą sortowania, filtrowania i eksportu danych.
 * Obsługuje również wyniki Long Range (dystans d4).
 * 
 * @author FClass Report Team
 * @version 10.1
 * @since 2025
 */

// Zapobiegaj wielokrotnemu ładowaniu
if (!defined('RESULTS_VIEW_LOADED')) {
    define('RESULTS_VIEW_LOADED', true);
}

function render_results_view(): void {
    global $config;
    
    // Wyczyść bufor wyjściowy na wypadek błędów w plikach włączanych
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // === INICJALIZACJA I WALIDACJA PARAMETRÓW ===
    require_once __DIR__ . '/../../app/event_results.php';
    require_once __DIR__ . '/../../app/cache.php';
    require_once __DIR__ . '/../../app/classmap.php';
    require_once __DIR__ . '/../../app/encoding.php';
    
    $maxYear = er_fetch_max_year();
    $minYear = $config['app']['min_year'];
    
    // Walidacja parametrów
    $year = validate_year_parameter($_GET['year'] ?? null, $maxYear);
    $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
    $showStats = isset($_GET['stats']) ? !empty($_GET['stats']) : true; // Domyślnie włączone
    
    // Sprawdź czy jest informacja o wydarzeniu przed walidacją sortowania
    $isLongRangeSort = false;
    if ($day !== '' && strlen($day) === 8) {
        $yearFromDay = (int)substr($day, 0, 4);
        $eventsForInfo = er_fetch_events_for_year($yearFromDay);
        $eventInfo = er_get_event_info($day, $eventsForInfo);
        $isLongRangeSort = ($eventInfo && isset($eventInfo['is_long_range'])) ? $eventInfo['is_long_range'] : false;
    }
    
    $sort = er_validate_sort_column($_GET['sort'] ?? null, $isLongRangeSort);
    $direction = er_validate_sort_direction($_GET['dir'] ?? '');
    
    // === POBIERANIE DANYCH ===
    $events = er_fetch_events_for_year($year);
    
    // Wybierz domyślny dzień jeśli nie podano
    if ($day === '' && !empty($events)) {
        // Znajdź ostatnie wydarzenie które nie jest Long Range
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (!($events[$i]['is_long_range'] ?? false)) {
                $day = $events[$i]['day'];
                break;
            }
        }
        // Jeśli wszystkie wydarzenia to Long Range, weź ostatnie
        if ($day === '' && !empty($events)) {
            $day = $events[count($events) - 1]['day'];
        }
    }
    
    // Jeśli nadal brak danych, spróbuj poprzednie lata
    if ($day === '' || empty($events)) {
        for ($searchYear = $year - 1; $searchYear >= $minYear; $searchYear--) {
            $searchEvents = er_fetch_events_for_year($searchYear);
            if (!empty($searchEvents)) {
                $year = $searchYear;
                $events = $searchEvents;
                // Znajdź ostatnie wydarzenie które nie jest Long Range
                for ($i = count($events) - 1; $i >= 0; $i--) {
                    if (!($events[$i]['is_long_range'] ?? false)) {
                        $day = $events[$i]['day'];
                        break;
                    }
                }
                // Jeśli wszystkie wydarzenia to Long Range, weź ostatnie
                if ($day === '' && !empty($events)) {
                    $day = $events[count($events) - 1]['day'];
                }
                break;
            }
        }
    }
    
    // Buduj dane wyników
    $resultData = ['meta' => ['day' => $day, 'opis' => ''], 'tables' => []];
    if ($day !== '') {
        $resultData = er_build_event_tables($day, $sort, $direction);
    }
    
    $metadata = $resultData['meta'];
    $tables = $resultData['tables'];
    $isLongRange = $metadata['is_long_range'] ?? false;
    $distanceLabel = $metadata['distance_label'] ?? '';
    
    // === OBLICZANIE STATYSTYK ===
    $stats = calculate_results_statistics($tables, $isLongRange);
    
    // === BREADCRUMBS ===
    $breadcrumbs = [
        ['title' => 'Strona główna', 'url' => '?'],
        ['title' => 'Wyniki zawodów', 'url' => '?view=results'],
    ];
    
    if ($day !== '') {
        $breadcrumbs[] = ['title' => "Zawody {$day}", 'url' => "?view=results&year={$year}&day={$day}"];
    }
    
    // === STATYSTYKI DLA DEBUG ===
    $debugStats = [];
    if ($config['app']['allow_debug']) {
        $debugStats = [
            'view' => 'results',
            'year' => $year,
            'day' => $day,
            'is_long_range' => $isLongRange,
            'distance_label' => $distanceLabel,
            'sort' => $sort,
            'direction' => $direction,
            'events_count' => count($events),
            'tables_count' => count($tables),
            'cache_stats' => cache_stats(),
        ];
    }
    
    // === RENDEROWANIE HTML ===
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <title>Wyniki zawodów <?php echo $day ? "– {$day}" : ''; ?> | <?php echo e($config['app']['name']); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Wyniki zawodów strzeleckich F-Class <?php echo $day ? "z dnia {$day}" : "roku {$year}"; ?>">
        
        <!-- Bootstrap CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="../assets/style.css">
        
        <style>
            .results-table {
                font-size: 0.9rem;
                table-layout: fixed;
            }
            .results-table td, .results-table th {
                padding: 0.5rem 0.25rem;
                vertical-align: middle;
            }
            
            <?php if ($isLongRange): ?>
            /* Szerokości kolumn dla Long Range (jeden dystans) */
            .results-table th:nth-child(1),
            .results-table td:nth-child(1) { width: 80px; }  /* Miejsce */
            .results-table th:nth-child(2),
            .results-table td:nth-child(2) { width: 250px; } /* Zawodnik - szersze */
            /* Dystans Long Range */
            .results-table th:nth-child(3),
            .results-table td:nth-child(3) { width: 100px; }  /* Wynik */
            .results-table th:nth-child(4),
            .results-table td:nth-child(4) { width: 80px; }   /* X */
            .results-table th:nth-child(5),
            .results-table td:nth-child(5) { width: 100px; }  /* MOA */
            /* Podsumowanie */
            .results-table th:nth-child(6),
            .results-table td:nth-child(6) { width: 120px; }  /* Wynik łączny */
            .results-table th:nth-child(7),
            .results-table td:nth-child(7) { width: 100px; }  /* Suma 10X */
            <?php else: ?>
            /* Standardowe szerokości kolumn (trzy dystanse) */
            .results-table th:nth-child(1),
            .results-table td:nth-child(1) { width: 60px; }  /* Miejsce */
            .results-table th:nth-child(2),
            .results-table td:nth-child(2) { width: 160px; } /* Zawodnik */
            /* 300m */
            .results-table th:nth-child(3),
            .results-table td:nth-child(3) { width: 70px; }  /* Wynik */
            .results-table th:nth-child(4),
            .results-table td:nth-child(4) { width: 50px; }  /* X */
            .results-table th:nth-child(5),
            .results-table td:nth-child(5) { width: 60px; }  /* MOA */
            /* 600m */
            .results-table th:nth-child(6),
            .results-table td:nth-child(6) { width: 70px; }  /* Wynik */
            .results-table th:nth-child(7),
            .results-table td:nth-child(7) { width: 50px; }  /* X */
            .results-table th:nth-child(8),
            .results-table td:nth-child(8) { width: 60px; }  /* MOA */
            /* 800m */
            .results-table th:nth-child(9),
            .results-table td:nth-child(9) { width: 70px; }  /* Wynik */
            .results-table th:nth-child(10),
            .results-table td:nth-child(10) { width: 50px; }  /* X */
            .results-table th:nth-child(11),
            .results-table td:nth-child(11) { width: 60px; }  /* MOA */
            /* Podsumowanie */
            .results-table th:nth-child(12),
            .results-table td:nth-child(12) { width: 80px; }  /* Śr. MOA */
            .results-table th:nth-child(13),
            .results-table td:nth-child(13) { width: 100px; } /* Wynik łączny */
            .results-table th:nth-child(14),
            .results-table td:nth-child(14) { width: 60px; }  /* Suma 10X */
            <?php endif; ?>
            
            .separator-column {
                border-left: 2px solid #dee2e6 !important;
            }
            .cell-empty {
                color: #6c757d;
                font-style: italic;
            }
            .cell-selected {
                font-weight: 600;
                background-color: rgba(25, 135, 84, 0.1);
            }
            .sort-link {
                text-decoration: none;
                color: inherit;
            }
            .sort-link:hover {
                color: #0d6efd;
            }
            .distance-header {
                background-color: #f8f9fa;
                font-weight: 600;
            }
            .stats-card {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .class-card {
                transition: transform 0.2s ease-in-out;
            }
            .class-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }
            .total-tens-column {
                background-color: rgba(25, 135, 84, 0.05);
                font-weight: 600;
                color: #28a745;
            }
            .long-range-indicator {
                background: linear-gradient(135deg, #ff6b6b 0%, #4ecdc4 100%);
                color: white;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 0.85rem;
                margin-left: 10px;
            }
            @media (max-width: 768px) {
                .results-table {
                    font-size: 0.8rem;
                }
                .results-table td, .results-table th {
                    padding: 0.25rem 0.1rem;
                }
            }
        </style>
    </head>
    <body class="bg-light">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h2 mb-1">
                        <i class="bi bi-trophy text-primary me-2"></i>
                        Wyniki zawodów
                        <?php if ($isLongRange): ?>
                            <span class="long-range-indicator">Long Range</span>
                        <?php endif; ?>
                    </h1>
                    <?php if ($day): ?>
                        <p class="text-muted mb-0">
                            <strong>Data:</strong> <?php echo format_event_date_lower($day); ?>
                            <?php if ($metadata['opis']): ?>
                                | <strong>Wydarzenie:</strong> <?php echo e($metadata['opis']); ?>
                            <?php endif; ?>
                            <?php if ($isLongRange): ?>
                                | <strong>Dystans:</strong> <?php echo e($distanceLabel); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php render_navigation('results'); ?>
            </div>
            
            <!-- Breadcrumbs -->
            <?php render_breadcrumbs($breadcrumbs); ?>
            
            <!-- Statystyki -->
            <?php if ($showStats && !empty($stats) && $day !== ''): ?>
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-people-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_participants']; ?></h3>
                                <p class="mb-0 small">Liczba zawodników</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-trophy-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['best_score']; ?></h3>
                                <p class="mb-0 small">Najlepszy wynik</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-bullseye fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['best_tens']; ?>X</h3>
                                <p class="mb-0 small">Najlepszy wynik 10X</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-crosshair fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['best_moa']; ?> MOA</h3>
                                <p class="mb-0 small">Najlepsze skupienie</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Formularz filtrowania -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" action="" class="row g-3 align-items-end">
                        <input type="hidden" name="view" value="results">
                        <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
                        <input type="hidden" name="dir" value="<?php echo e($direction); ?>">
                        
                        <div class="col-md-2">
                            <label for="year" class="form-label">
                                <i class="bi bi-calendar me-1"></i>Rok
                            </label>
                            <select id="year" name="year" class="form-select">
                                <?php for ($y = $minYear; $y <= $maxYear; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === $year) ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-5">
                            <label for="day" class="form-label">
                                <i class="bi bi-flag me-1"></i>Zawody
                            </label>
                            <select id="day" name="day" class="form-select">
                                <?php if (empty($events)): ?>
                                    <option value="">Brak dostępnych zawodów</option>
                                <?php else: ?>
                                    <?php foreach ($events as $event): ?>
                                        <option value="<?php echo e($event['day']); ?>" 
                                                <?php echo ($event['day'] === $day) ? 'selected' : ''; ?>>
                                            <?php 
                                            $eventLabel = format_event_date_lower($event['day']) . ' – ' . $event['opis'];
                                            if ($event['is_long_range'] ?? false) {
                                                $eventLabel .= ' [Long Range]';
                                            }
                                            echo e($eventLabel);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="stats" id="stats" value="1"
                                       <?php echo $showStats ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stats">
                                    Pokaż statystyki
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <?php if ($day !== ''): ?>
                                <?php render_export_buttons('export/results.php', ['day' => $day]); ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Informacje o sortowaniu -->
            <?php if ($day !== ''): ?>
                <div class="alert alert-info d-flex align-items-center">
                    <i class="bi bi-info-circle me-2"></i>
                    <div>
                        <strong>Sortowanie:</strong> 
                        <?php 
                        $sortLabels = [
                            'rank' => 'Miejsce',
                            'total' => 'Wynik łączny',
                            'sum_x' => 'Suma 10X',
                            'avg_moa' => $isLongRange ? 'MOA' : 'Średnia MOA',
                            'res_d1' => 'Wynik 300m',
                            'res_d2' => 'Wynik 600m', 
                            'res_d3' => 'Wynik 800m',
                            'res_d4' => 'Wynik ' . $distanceLabel,
                            'x_d4' => 'X ' . $distanceLabel,
                            'moa_d4' => 'MOA ' . $distanceLabel,
                        ];
                        $sortLabel = $sortLabels[$sort] ?? $sort;
                        $dirLabel = $direction === 'asc' ? 'rosnąco' : 'malejąco';
                        echo e($sortLabel . ' (' . $dirLabel . ')');
                        ?>
                        <small class="text-muted ms-2">Kliknij nagłówek kolumny aby zmienić sortowanie</small>
                        <?php if ($isLongRange): ?>
                            <br><small class="text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Wyniki Long Range nie są wliczane do rankingu rocznego
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Wyniki -->
            <?php if ($day === ''): ?>
                <?php render_no_data_message('Wybierz rok i zawody aby wyświetlić wyniki.'); ?>
            <?php elseif (empty($tables)): ?>
                <?php render_no_data_message('Brak wyników dla wybranych zawodów.', 'warning'); ?>
            <?php else: ?>
                <?php 
                // Sortuj klasy numerycznie
                $sortedClasses = sort_classes(array_keys($tables), 'numeric');
                
                foreach ($sortedClasses as $classKey):
                    $classResults = $tables[$classKey];
                    if (empty($classResults)) continue;
                    
                    $className = class_map_name($classKey);
                    $participantCount = count($classResults);
                ?>
                    <div class="card class-card mb-4 shadow-sm">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-people me-2"></i>
                                Klasa: <?php echo e($className); ?>
                            </h5>
                            <div>
                                <span class="badge bg-light text-dark me-2">
                                    <?php echo $participantCount; ?> zawodników
                                </span>
                                <button class="btn btn-sm btn-outline-light" 
                                        type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#class-<?php echo e($classKey); ?>" 
                                        aria-expanded="true">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="collapse show" id="class-<?php echo e($classKey); ?>">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover results-table mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center">
                                                <?php echo generate_sort_link('Miejsce', 'rank', $sort, $direction, [
                                                    'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                ]); ?>
                                            </th>
                                            <th>Zawodnik</th>
                                            
                                            <?php if ($isLongRange): ?>
                                                <!-- Long Range - jeden dystans -->
                                                <th colspan="3" class="text-center separator-column distance-header">
                                                    <?php echo e($distanceLabel); ?>
                                                </th>
                                            <?php else: ?>
                                                <!-- Standardowe - trzy dystanse -->
                                                <th colspan="3" class="text-center separator-column distance-header">300m</th>
                                                <th colspan="3" class="text-center separator-column distance-header">600m</th>
                                                <th colspan="3" class="text-center separator-column distance-header">800m</th>
                                                <th class="text-center separator-column">
                                                    <?php echo generate_sort_link('Śr. MOA', 'avg_moa', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                            <?php endif; ?>
                                            
                                            <th class="text-end separator-column">Wynik łączny</th>
                                            <th class="text-center total-tens-column">
                                                <?php echo generate_sort_link('10X', 'sum_x', $sort, $direction, [
                                                    'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                ]); ?>
                                            </th>
                                        </tr>
                                        <tr class="table-secondary small">
                                            <th></th>
                                            <th></th>
                                            
                                            <?php if ($isLongRange): ?>
                                                <!-- Long Range - podtytuły -->
                                                <th class="separator-column">
                                                    <?php echo generate_sort_link('Wynik', 'res_d4', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('X', 'x_d4', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('MOA', 'moa_d4', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                            <?php else: ?>
                                                <!-- Standardowe - podtytuły -->
                                                <!-- 300m -->
                                                <th class="separator-column">
                                                    <?php echo generate_sort_link('Wynik', 'res_d1', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('X', 'x_d1', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('MOA', 'moa_d1', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <!-- 600m -->
                                                <th class="separator-column">
                                                    <?php echo generate_sort_link('Wynik', 'res_d2', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('X', 'x_d2', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('MOA', 'moa_d2', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <!-- 800m -->
                                                <th class="separator-column">
                                                    <?php echo generate_sort_link('Wynik', 'res_d3', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('X', 'x_d3', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th>
                                                    <?php echo generate_sort_link('MOA', 'moa_d3', $sort, $direction, [
                                                        'view' => 'results', 'year' => $year, 'day' => $day, 'stats' => $showStats ? '1' : '0'
                                                    ]); ?>
                                                </th>
                                                <th class="separator-column"></th>
                                            <?php endif; ?>
                                            
                                            <th class="separator-column"></th>
                                            <th class="total-tens-column"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classResults as $result): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <span class="badge bg-primary fs-6">
                                                        <?php echo (int)$result['rank']; ?>
                                                    </span>
                                                </td>
                                                <td class="fw-semibold">
                                                    <?php echo e(trim(($result['fname'] ?? '') . ' ' . ($result['lname'] ?? ''))); ?>
                                                </td>
                                                
                                                <?php if ($isLongRange): ?>
                                                    <!-- Long Range - wyniki d4 -->
                                                    <td class="separator-column">
                                                        <?php echo format_display_number($result['res_d4'] ?? null, 0); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo format_display_number($result['x_d4'] ?? null, 0); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo ($result['moa_d4'] !== null) ? e($result['moa_d4']) : '–'; ?>
                                                    </td>
                                                <?php else: ?>
                                                    <!-- Standardowe - wyniki d1, d2, d3 -->
                                                    <?php
                                                    $distances = [
                                                        ['res' => 'res_d1', 'x' => 'x_d1', 'moa' => 'moa_d1', 'first' => true],
                                                        ['res' => 'res_d2', 'x' => 'x_d2', 'moa' => 'moa_d2', 'first' => true],
                                                        ['res' => 'res_d3', 'x' => 'x_d3', 'moa' => 'moa_d3', 'first' => true],
                                                    ];
                                                    
                                                    foreach ($distances as $dist):
                                                        $resValue = $result[$dist['res']] ?? null;
                                                        $xValue = $result[$dist['x']] ?? null;
                                                        $moaValue = $result[$dist['moa']] ?? null;
                                                        
                                                        $separatorClass = $dist['first'] ? 'separator-column' : '';
                                                    ?>
                                                        <td class="<?php echo $separatorClass; ?>">
                                                            <?php echo format_display_number($resValue, 0); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo format_display_number($xValue, 0); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo ($moaValue !== null) ? e($moaValue) : '–'; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                    
                                                    <td class="text-center separator-column">
                                                        <?php 
                                                        $avgMoa = $result['avg_moa'] ?? null;
                                                        echo ($avgMoa !== null) ? format_display_number($avgMoa, 3) : '–';
                                                        ?>
                                                    </td>
                                                <?php endif; ?>
                                                
                                                <td class="text-end fw-bold separator-column">
                                                    <?php echo format_display_number($result['total'], 0); ?>
                                                </td>
                                                <td class="text-center total-tens-column">
                                                    <?php echo format_display_number($result['sum_x'] ?? 0, 0); ?>X
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Footer (uproszczony) -->
            <?php render_footer([]); ?>
            
            <!-- Debug info -->
            <?php render_debug_stats($debugStats); ?>
        </div>
        
        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        
        <!-- Custom JS -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit form on year change
            const yearSelect = document.getElementById('year');
            if (yearSelect) {
                yearSelect.addEventListener('change', function() {
                    // Reset day selection when year changes
                    const daySelect = document.getElementById('day');
                    if (daySelect) {
                        daySelect.selectedIndex = 0;
                    }
                    this.form.submit();
                });
            }
            
            // Auto-submit form on day (zawody) change
            const daySelect = document.getElementById('day');
            if (daySelect) {
                daySelect.addEventListener('change', function() {
                    this.form.submit();
                });
            }
            
            // Auto-submit form on stats checkbox change
            const statsCheckbox = document.getElementById('stats');
            if (statsCheckbox) {
                statsCheckbox.addEventListener('change', function() {
                    this.form.submit();
                });
            }
            
            // Highlight selected table rows on hover
            const tableRows = document.querySelectorAll('.results-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'rgba(13, 110, 253, 0.1)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
            
            // Tooltip dla sortowania
            const sortLinks = document.querySelectorAll('.sort-link');
            sortLinks.forEach(link => {
                link.addEventListener('mouseenter', function() {
                    this.style.cursor = 'pointer';
                });
            });
            
            // Expand/collapse all functionality
            const expandAllBtn = document.createElement('button');
            expandAllBtn.className = 'btn btn-sm btn-outline-secondary position-fixed';
            expandAllBtn.style.bottom = '20px';
            expandAllBtn.style.right = '20px';
            expandAllBtn.style.zIndex = '1000';
            expandAllBtn.innerHTML = '<i class="bi bi-arrows-expand"></i>';
            expandAllBtn.title = 'Rozwiń/zwiń wszystkie tabele';
            
            let allExpanded = true;
            expandAllBtn.addEventListener('click', function() {
                const collapses = document.querySelectorAll('.collapse');
                const icon = this.querySelector('i');
                
                collapses.forEach(collapse => {
                    const bsCollapse = new bootstrap.Collapse(collapse, {
                        toggle: false
                    });
                    
                    if (allExpanded) {
                        bsCollapse.hide();
                    } else {
                        bsCollapse.show();
                    }
                });
                
                allExpanded = !allExpanded;
                icon.className = allExpanded ? 'bi bi-arrows-collapse' : 'bi bi-arrows-expand';
            });
            
            // Add button only if there are multiple classes
            const classCards = document.querySelectorAll('.class-card');
            if (classCards.length > 1) {
                document.body.appendChild(expandAllBtn);
            }
        });
        </script>
    </body>
    </html>
    <?php
}

/**
 * Formatuje datę wydarzenia do czytelnej formy z małą literą miesiąca
 * 
 * @param string $day Dzień w formacie YYYYMMDD
 * @return string Sformatowana data
 */
function format_event_date_lower(string $day): string {
    if (strlen($day) !== 8) {
        return $day;
    }
    
    $year = substr($day, 0, 4);
    $month = substr($day, 4, 2);
    $dayNum = (int)substr($day, 6, 2); // int() usuwa wiodące zero
    
    $monthNames = [
        '01' => 'stycznia', '02' => 'lutego', '03' => 'marca', '04' => 'kwietnia',
        '05' => 'maja', '06' => 'czerwca', '07' => 'lipca', '08' => 'sierpnia',
        '09' => 'września', '10' => 'października', '11' => 'listopada', '12' => 'grudnia'
    ];
    
    $monthName = $monthNames[$month] ?? $month;
    return "{$dayNum} {$monthName} {$year}";
}

/**
 * Oblicza statystyki dla wyników zawodów
 * 
 * @param array $tables Tabele wyników
 * @param bool $isLongRange Czy to Long Range
 * @return array Statystyki
 */
function calculate_results_statistics(array $tables, bool $isLongRange = false): array {
    $stats = [
        'total_participants' => 0,
        'best_score' => 0,
        'best_tens' => 0,
        'best_moa' => null,
    ];
    
    if (empty($tables)) {
        return $stats;
    }
    
    $allScores = [];
    $allTens = [];
    $allMoas = [];
    
    foreach ($tables as $classResults) {
        foreach ($classResults as $result) {
            $stats['total_participants']++;
            
            // Zbierz wyniki
            $totalScore = (int)($result['total'] ?? 0);
            $totalTens = (int)($result['sum_x'] ?? 0);
            
            if ($totalScore > 0) {
                $allScores[] = $totalScore;
                $allTens[] = $totalTens;
            }
            
            // Zbierz MOA (jeśli dostępne)
            // Dla Long Range używamy moa_d4, dla standardowych avg_moa
            $moaValue = null;
            if ($isLongRange && isset($result['moa_d4']) && $result['moa_d4'] !== null) {
                $moaValue = (float)$result['moa_d4'];
            } elseif (!$isLongRange && isset($result['avg_moa']) && $result['avg_moa'] !== null) {
                $moaValue = (float)$result['avg_moa'];
            }
            
            if ($moaValue !== null && $moaValue > 0) {
                $allMoas[] = $moaValue;
            }
        }
    }
    
    // Oblicz najlepsze wyniki
    if (!empty($allScores)) {
        $stats['best_score'] = max($allScores);
    }
    
    if (!empty($allTens)) {
        $stats['best_tens'] = max($allTens);
    }
    
    if (!empty($allMoas)) {
        $stats['best_moa'] = number_format(min($allMoas), 3, '.', '');
    } else {
        $stats['best_moa'] = '–';
    }
    
    return $stats;
}
?>