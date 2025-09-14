<?php
/**
 * Widok rankingu rocznego
 * 
 * Renderuje interfejs rankingu rocznego z tabelami klas,
 * filtrami oraz opcjami eksportu.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

if (!defined('RANKING_VIEW_LOADED')) {
    define('RANKING_VIEW_LOADED', true);
}

function render_ranking_view(): void {
    global $config;
    
    // === INICJALIZACJA ===
    require_once __DIR__ . '/../../app/query_ranking.php';
    require_once __DIR__ . '/../../app/cache.php';
    
    $maxYear = fetch_max_year();
    $minYear = $config['app']['min_year'];
    
    // === WALIDACJA PARAMETRÓW ===
    $selectedYear = validate_year_parameter($_GET['year'] ?? null, $maxYear);
    $classFilter = sanitize_input($_GET['class'] ?? '', ['max_length' => 10]);
    $showStats = isset($_GET['stats']) ? !empty($_GET['stats']) : true; // Domyślnie włączone
    
    // === POBIERANIE DANYCH ===
    $cacheKey = "ranking_view_{$selectedYear}_{$classFilter}";
    $cached = cache_get($cacheKey, $config['app']['cache_ttl']);
    
    if ($cached !== null) {
        $rankingData = $cached;
    } else {
        $rankingData = build_annual_ranking_with_columns($selectedYear);
        $rankingData = to_utf8($rankingData);
        cache_set($cacheKey, $rankingData);
    }
    
    $events = $rankingData['events'] ?? [];
    $allData = $rankingData['data'] ?? [];
    $meta = $rankingData['meta'] ?? [];
    
    // Filtruj dane po klasie jeśli wybrano
    $displayData = $allData;
    if ($classFilter !== '' && isset($allData[$classFilter])) {
        $displayData = [$classFilter => $allData[$classFilter]];
    }
    
    // Usuń puste klasy
    $displayData = array_filter($displayData, fn($participants) => !empty($participants));
    
    // === STATYSTYKI ===
    $stats = [
        'total_events' => count($events),
        'me_events' => $meta['me_events'] ?? 0,
        'total_classes' => count($displayData),
        'total_participants' => array_sum(array_map('count', $displayData)),
    ];
    
    // === BREADCRUMBS ===
    $breadcrumbs = [
        ['title' => 'Strona główna', 'url' => '?'],
        ['title' => 'Ranking roczny', 'url' => '?view=ranking'],
        ['title' => "Rok {$selectedYear}", 'url' => "?view=ranking&year={$selectedYear}"],
    ];
    
    if ($classFilter !== '') {
        $className = class_map_name($classFilter);
        $breadcrumbs[] = ['title' => "Klasa {$className}", 'url' => "?view=ranking&year={$selectedYear}&class={$classFilter}"];
    }
    
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <title>Ranking roczny ligi F-Class.pl za rok <?php echo $selectedYear; ?> | <?php echo e($config['app']['name']); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Ranking roczny zawodów strzeleckich F-Class <?php echo $selectedYear; ?>">
        
        <!-- Bootstrap CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="../assets/style.css">
        
        <style>
            .ranking-table {
                font-size: 0.9rem;
            }
            .ranking-table td, .ranking-table th {
                padding: 0.4rem 0.2rem;
                vertical-align: middle;
                border-left: 1px solid #dee2e6;
            }
            .ranking-table td:first-child, .ranking-table th:first-child {
                border-left: none;
            }
            .event-column {
                min-width: 75px;
                text-align: center;
            }
            .event-header {
                font-size: 0.75rem;
                line-height: 1.2;
            }
            .cell-selected {
                font-weight: 700;
            }
            .cell-empty {
                color: #6c757d;
                font-style: italic;
            }
            .score-value {
                display: block;
                line-height: 1.3;
            }
            .tens-value {
                display: block;
                font-size: 0.8rem;
                color: #6c757d;
            }
            .cell-selected .tens-value {
                color: #198754;
                font-weight: 600;
            }
            .participant-row:hover {
                background-color: rgba(13, 110, 253, 0.05);
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
                font-weight: 700;
            }
            @media (max-width: 768px) {
                .ranking-table {
                    font-size: 0.8rem;
                }
                .ranking-table td, .ranking-table th {
                    padding: 0.25rem 0.1rem;
                }
                .event-header {
                    font-size: 0.7rem;
                }
            }
        </style>
    </head>
    <body class="bg-light">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="h2 mb-2">
                        <i class="bi bi-trophy text-warning me-2"></i>
                        Ranking roczny ligi F-Class.pl za rok <?php echo $selectedYear; ?>
                    </h1>
                    <p class="text-muted mb-0">
                        <i class="bi bi-calendar-event me-1"></i>
                        <?php echo $stats['total_events']; ?> wydarzeń 
                        (w tym <?php echo $stats['me_events']; ?> ME)
                        
                        <?php if ($classFilter): ?>
                            | <strong>Klasa:</strong> <?php echo e(class_map_name($classFilter)); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php render_navigation('ranking'); ?>
            </div>
            
            <!-- Breadcrumbs -->
            <?php render_breadcrumbs($breadcrumbs); ?>
            
            <!-- Statystyki -->
            <?php if ($showStats): ?>
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-people-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_participants']; ?></h3>
                                <p class="mb-0 small">Zawodnicy w rankingu</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-grid-3x3-gap-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_classes']; ?></h3>
                                <p class="mb-0 small">Klasy sprzętowe</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-flag-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_events']; ?></h3>
                                <p class="mb-0 small">Wszystkich wydarzeń</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-award-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['me_events']; ?></h3>
                                <p class="mb-0 small">Mistrzostwa Europy</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Formularz filtrowania -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" action="" class="row g-3 align-items-end">
                        <input type="hidden" name="view" value="ranking">
                        
                        <div class="col-md-2">
                            <label for="year" class="form-label">
                                <i class="bi bi-calendar me-1"></i>Rok
                            </label>
                            <select id="year" name="year" class="form-select">
                                <?php for ($y = $minYear; $y <= $maxYear; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === $selectedYear) ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="class" class="form-label">
                                <i class="bi bi-funnel me-1"></i>Klasa
                            </label>
                            <select id="class" name="class" class="form-select">
                                <option value="">Wszystkie klasy</option>
                                <?php 
                                $availableClasses = sort_classes(array_keys($allData), 'numeric');
                                foreach ($availableClasses as $classId): 
                                    if (empty($allData[$classId])) continue;
                                    $className = class_map_name($classId);
                                    $participantCount = count($allData[$classId]);
                                ?>
                                    <option value="<?php echo e($classId); ?>" 
                                            <?php echo ($classId === $classFilter) ? 'selected' : ''; ?>>
                                        <?php echo e($className . " ({$participantCount})"); ?>
                                    </option>
                                <?php endforeach; ?>
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
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Filtruj
                            </button>
                        </div>
                        
                        <div class="col-md-3">
                            <?php 
                            $exportParams = ['year' => $selectedYear];
                            if ($classFilter) $exportParams['class'] = $classFilter;
                            render_export_buttons('export/ranking.php', $exportParams);
                            ?>
                            
                            <?php if ($classFilter): ?>
                                <a href="?view=ranking&year=<?php echo $selectedYear; ?>" 
                                   class="btn btn-outline-secondary btn-sm ms-2" 
                                   title="Usuń filtr klasy">
                                    <i class="bi bi-x-circle"></i> Wszystkie
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Informacje o kwalifikacji -->
            <div class="alert alert-info d-flex align-items-start">
                <i class="bi bi-info-circle-fill me-2 mt-1"></i>
                <div>
                    <strong>Kwalifikacja do rankingu:</strong>
                    Minimum <?php echo $config['ranking']['min_me_events']; ?> start w ME + 
                    <?php echo $config['ranking']['min_other_events']; ?> starty w innych zawodach (z wynikiem > 0).
                    <strong>Wynik łączny</strong> = najlepszy ME + 2 najlepsze inne wydarzenia.
                    <br>
                    <small class="text-muted">
                        <strong>Legenda:</strong> 
                        <span class="fw-bold">Pogrubione</span> = wyniki użyte w rankingu | 
                        <span class="text-success">10X</span> = centralne dziesiątki
                    </small>
                </div>
            </div>
            
            <!-- Ranking -->
            <?php if (empty($displayData)): ?>
                <?php render_no_data_message('Brak danych rankingu dla wybranego roku/klasy.', 'warning'); ?>
            <?php else: ?>
                <?php 
                $sortedClasses = sort_classes(array_keys($displayData), 'numeric');
                
                foreach ($sortedClasses as $classKey):
                    $classParticipants = $displayData[$classKey];
                    if (empty($classParticipants)) continue;
                    
                    $className = class_map_name($classKey);
                    $participantCount = count($classParticipants);
                ?>
                    <div class="card class-card mb-4 shadow-sm">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-award me-2"></i>
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
                                <table class="table ranking-table table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 70px;" class="text-center">Miejsce</th>
                                            <th style="width: 200px;">Zawodnik</th>
                                            <?php foreach ($events as $event): ?>
                                                <?php
                                                // Konwersja YYYYMMDD na DD-MM-YYYY
                                                $year = substr($event, 0, 4);
                                                $month = substr($event, 4, 2);
                                                $day = substr($event, 6, 2);
                                                $formattedDate = "{$day}-{$month}-{$year}";
                                                ?>
                                                <th class="event-column">
                                                    <div class="event-header">
                                                        <?php echo e($formattedDate); ?>
                                                    </div>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="text-end event-column">Wynik łączny</th>
                                            <th class="text-center total-tens-column" style="width: 75px;">10X</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classParticipants as $participant): ?>
                                            <?php
                                            // Oblicz sumę centralnych dziesiątek z wybranych zawodów
                                            $totalSelectedTens = 0;
                                            foreach ($participant['cells'] ?? [] as $eventKey => $cellData) {
                                                if (($cellData['selected'] ?? false) && isset($cellData['tens'])) {
                                                    $totalSelectedTens += (int)$cellData['tens'];
                                                }
                                            }
                                            ?>
                                            <tr class="participant-row">
                                                <td class="text-center">
                                                    <span class="badge bg-primary fs-6">
                                                        <?php echo (int)$participant['rank']; ?>
                                                    </span>
                                                </td>
                                                <td class="fw-semibold">
                                                    <?php echo e(trim(($participant['fname'] ?? '') . ' ' . ($participant['lname'] ?? ''))); ?>
                                                </td>
                                                
                                                <?php foreach ($events as $event): ?>
                                                    <?php 
                                                    $cellData = $participant['cells'][$event] ?? null;
                                                    $score = $cellData['score'] ?? null;
                                                    $tens = $cellData['tens'] ?? 0;
                                                    $isSelected = $cellData['selected'] ?? false;
                                                    
                                                    $cellClass = 'event-column';
                                                    if ($score === null) {
                                                        $cellClass .= ' cell-empty';
                                                        $displayValue = '–';
                                                        $tensDisplay = '';
                                                    } else {
                                                        $displayValue = format_display_number($score, 0);
                                                        $tensDisplay = $tens . 'X';
                                                        if ($isSelected) {
                                                            $cellClass .= ' cell-selected';
                                                        }
                                                    }
                                                    ?>
                                                    <td class="<?php echo $cellClass; ?>">
                                                        <?php if ($score !== null): ?>
                                                            <span class="score-value"><?php echo $displayValue; ?></span>
                                                            <span class="tens-value"><?php echo $tensDisplay; ?></span>
                                                        <?php else: ?>
                                                            <?php echo $displayValue; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                
                                                <td class="text-end fw-bold event-column">
                                                    <?php echo format_display_number($participant['total'] ?? 0, 0); ?>
                                                </td>
                                                
                                                <td class="text-center total-tens-column">
                                                    <?php echo $totalSelectedTens; ?>X
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
            
            <!-- Footer -->
            <?php 
            $footerInfo = [];
            
            if ($classFilter) {
                $footerInfo['Filtr'] = class_map_name($classFilter);
            }
            
            render_footer($footerInfo);
            ?>
            
            <!-- Debug info -->
            <?php 
            if ($config['app']['allow_debug']) {
                $debugStats = [
                    'view' => 'ranking',
                    'year' => $selectedYear,
                    'class_filter' => $classFilter,
                    'show_stats' => $showStats,
                    'cache_key' => $cacheKey,
                    'events_count' => count($events),
                    'classes_count' => count($displayData),
                    'participants_count' => $stats['total_participants'],
                ];
                render_debug_stats($debugStats);
            }
            ?>
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
                    // Reset class selection when year changes  
                    const classSelect = document.getElementById('class');
                    if (classSelect) {
                        classSelect.selectedIndex = 0;
                    }
                    this.form.submit();
                });
            }
            
            // Form submission - remove empty parameters to prevent 403
            const form = document.querySelector('form[method="get"]');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Remove empty parameters before submit
                    const formData = new FormData(this);
                    const params = new URLSearchParams();
                    
                    for (let [key, value] of formData.entries()) {
                        if (value && value.trim() !== '') {
                            params.append(key, value);
                        }
                    }
                    
                    // Rebuild URL without empty parameters
                    if (params.toString()) {
                        window.location.href = '?' + params.toString();
                        e.preventDefault();
                    }
                });
            }
            
            // Tooltip dla wybranych komórek
            const selectedCells = document.querySelectorAll('.cell-selected');
            selectedCells.forEach(cell => {
                cell.style.cursor = 'help';
                cell.title = 'Wynik użyty w rankingu';
            });
            
            // Sticky header dla tabel
            const tables = document.querySelectorAll('.ranking-table');
            tables.forEach(table => {
                const thead = table.querySelector('thead');
                if (thead) {
                    thead.style.position = 'sticky';
                    thead.style.top = '0';
                    thead.style.zIndex = '10';
                }
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
            
            // Highlight participant row on hover
            const participantRows = document.querySelectorAll('.participant-row');
            participantRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'rgba(13, 110, 253, 0.08)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
            
            // Performance: Virtual scrolling for large tables (future enhancement)
            // TODO: Implement virtual scrolling for tables with 100+ participants
        });
        </script>
    </body>
    </html>
    <?php
}
?>