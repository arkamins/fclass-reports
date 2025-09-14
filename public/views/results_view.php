<?php
/**
 * Widok wyników zawodów
 * 
 * Renderuje interfejs do przeglądania wyników konkretnych zawodów
 * z obsługą sortowania, filtrowania i eksportu danych.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

if (!defined('RESULTS_VIEW_LOADED')) {
    define('RESULTS_VIEW_LOADED', true);
}

function render_results_view(): void {
    global $config;
    
    // === INICJALIZACJA I WALIDACJA PARAMETRÓW ===
    require_once __DIR__ . '/../../app/event_results.php';
    require_once __DIR__ . '/../../app/cache.php';
    
    $maxYear = er_fetch_max_year();
    $minYear = $config['app']['min_year'];
    
    // Walidacja parametrów
    $year = validate_year_parameter($_GET['year'] ?? null, $maxYear);
    $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
    $sort = er_validate_sort_column($_GET['sort'] ?? null);
    $direction = er_validate_sort_direction($_GET['dir'] ?? '');
    
    // === POBIERANIE DANYCH ===
    $events = er_fetch_events_for_year($year);
    
    // Wybierz domyślny dzień jeśli nie podano
    if ($day === '' && !empty($events)) {
        $day = $events[count($events) - 1]['day']; // Ostatnie zawody
    }
    
    // Jeśli nadal brak danych, spróbuj poprzednie lata
    if ($day === '' || empty($events)) {
        for ($searchYear = $year - 1; $searchYear >= $minYear; $searchYear--) {
            $searchEvents = er_fetch_events_for_year($searchYear);
            if (!empty($searchEvents)) {
                $year = $searchYear;
                $events = $searchEvents;
                $day = $searchEvents[count($searchEvents) - 1]['day'];
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
            }
            .results-table td, .results-table th {
                padding: 0.5rem 0.25rem;
                vertical-align: middle;
            }
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
                    </h1>
                    <?php if ($day): ?>
                        <p class="text-muted mb-0">
                            <strong>Data:</strong> <?php echo e($metadata['day']); ?>
                            <?php if ($metadata['opis']): ?>
                                | <strong>Wydarzenie:</strong> <?php echo e($metadata['opis']); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php render_navigation('results'); ?>
            </div>
            
            <!-- Breadcrumbs -->
            <?php render_breadcrumbs($breadcrumbs); ?>
            
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
                            <select id="year" name="year" class="form-select" onchange="this.form.submit()">
                                <?php for ($y = $minYear; $y <= $maxYear; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === $year) ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
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
                                            <?php echo e($event['day'] . ' – ' . $event['opis']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Pokaż
                            </button>
                        </div>
                        
                        <div class="col-md-2">
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
                            'avg_moa' => 'Średnia MOA',
                            'res_d1' => 'Wynik 300m',
                            'res_d2' => 'Wynik 600m', 
                            'res_d3' => 'Wynik 800m',
                        ];
                        $sortLabel = $sortLabels[$sort] ?? $sort;
                        $dirLabel = $direction === 'asc' ? 'rosnąco' : 'malejąco';
                        echo e($sortLabel . ' (' . $dirLabel . ')');
                        ?>
                        <small class="text-muted ms-2">Kliknij nagłówek kolumny aby zmienić sortowanie</small>
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
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-people me-2"></i>
                                Klasa: <?php echo e($className); ?>
                            </h5>
                            <span class="badge bg-light text-dark">
                                <?php echo $participantCount; ?> zawodników
                            </span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover results-table mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width: 80px;" class="text-center">
                                            <?php echo generate_sort_link('Miejsce', 'rank', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>Zawodnik</th>
                                        <th colspan="3" class="text-center separator-column distance-header">300m</th>
                                        <th colspan="3" class="text-center separator-column distance-header">600m</th>
                                        <th colspan="3" class="text-center separator-column distance-header">800m</th>
                                        <th class="text-center separator-column">
                                            <?php echo generate_sort_link('Śr. MOA', 'avg_moa', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th class="text-end separator-column">Wynik łączny</th>
                                    </tr>
                                    <tr class="table-secondary small">
                                        <th></th>
                                        <th></th>
                                        <!-- 300m -->
                                        <th class="separator-column">
                                            <?php echo generate_sort_link('Wynik', 'res_d1', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('X', 'x_d1', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('MOA', 'moa_d1', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <!-- 600m -->
                                        <th class="separator-column">
                                            <?php echo generate_sort_link('Wynik', 'res_d2', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('X', 'x_d2', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('MOA', 'moa_d2', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <!-- 800m -->
                                        <th class="separator-column">
                                            <?php echo generate_sort_link('Wynik', 'res_d3', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('X', 'x_d3', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th>
                                            <?php echo generate_sort_link('MOA', 'moa_d3', $sort, $direction, [
                                                'view' => 'results', 'year' => $year, 'day' => $day
                                            ]); ?>
                                        </th>
                                        <th class="separator-column"></th>
                                        <th class="separator-column"></th>
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
                                            
                                            <?php
                                            // Renderuj wyniki dla trzech dystansów
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
                                            <td class="text-end fw-bold separator-column">
                                                <?php echo format_display_number($result['total'], 0); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Footer -->
            <?php 
            $footerInfo = [];
            if ($day !== '') {
                $footerInfo['Zawody'] = $metadata['day'];
                if ($metadata['opis']) {
                    $footerInfo['Wydarzenie'] = $metadata['opis'];
                }
                $footerInfo['Klasy'] = count($tables);
                $totalParticipants = array_sum(array_map('count', $tables));
                $footerInfo['Zawodnicy'] = $totalParticipants;
            }
            
            render_footer($footerInfo);
            ?>
            
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
        });
        </script>
    </body>
    </html>
    <?php
}
?>