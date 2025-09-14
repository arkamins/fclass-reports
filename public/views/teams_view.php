<?php
/**
 * Widok zespołów Mistrzostw Europy
 * 
 * Renderuje interfejs do przeglądania zespołów ME z rankingiem,
 * filtrami, statystykami oraz opcjami eksportu.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

if (!defined('TEAMS_VIEW_LOADED')) {
    define('TEAMS_VIEW_LOADED', true);
}

function render_teams_view(): void {
    global $config;
    
    // === INICJALIZACJA I WALIDACJA PARAMETRÓW ===
    require_once __DIR__ . '/../../app/teams.php';
    require_once __DIR__ . '/../../app/cache.php';
    
    $maxYear = t_fetch_max_year();
    $minYear = $config['app']['min_year'];
    
    // Walidacja parametrów
    $year = validate_year_parameter($_GET['year'] ?? null, $maxYear);
    $day = sanitize_input($_GET['day'] ?? '', ['max_length' => 8]);
    $classFilter = sanitize_input($_GET['class'] ?? '', ['max_length' => 10]);
    $showStats = !empty($_GET['stats']);
    $searchTerm = sanitize_input($_GET['search'] ?? '', ['max_length' => 50]);
    
    // === POBIERANIE DANYCH ===
    $events = t_fetch_me_events_for_year($year);
    
    // Wybierz domyślny dzień jeśli nie podano
    if ($day === '' && !empty($events)) {
        $day = $events[count($events) - 1]['day']; // Ostatnie ME
    }
    
    // Jeśli nadal brak danych, spróbuj poprzednie lata
    if ($day === '' || empty($events)) {
        for ($searchYear = $year - 1; $searchYear >= $minYear; $searchYear--) {
            $searchEvents = t_fetch_me_events_for_year($searchYear);
            if (!empty($searchEvents)) {
                $year = $searchYear;
                $events = $searchEvents;
                $day = $searchEvents[count($searchEvents) - 1]['day'];
                break;
            }
        }
    }
    
    // Pobierz dane zespołów
    $teamsData = [];
    $eventMeta = null;
    if ($day !== '') {
        $teamsData = t_fetch_teams_by_class($day);
        
        // Znajdź metadane wydarzenia
        foreach ($events as $event) {
            if ($event['day'] === $day) {
                $eventMeta = $event;
                break;
            }
        }
    }
    
    // Filtruj dane po klasie jeśli wybrano
    $displayData = $teamsData;
    if ($classFilter !== '' && isset($teamsData[$classFilter])) {
        $displayData = [$classFilter => $teamsData[$classFilter]];
    }
    
    // Wyszukiwanie zespołów
    if ($searchTerm !== '' && !empty($displayData)) {
        $displayData = t_filter_teams_by_search($displayData, $searchTerm);
    }
    
    // === STATYSTYKI ===
    $stats = [];
    if ($day !== '') {
        $stats = t_get_teams_statistics($day);
        
        // Dostosuj statystyki do filtrów
        if ($classFilter !== '' || $searchTerm !== '') {
            $stats = t_calculate_filtered_stats($displayData, $stats);
        }
    }
    
    // === BREADCRUMBS ===
    $breadcrumbs = [
        ['title' => 'Strona główna', 'url' => '?'],
        ['title' => 'Zespoły ME', 'url' => '?view=teams'],
    ];
    
    if ($day !== '') {
        $breadcrumbs[] = ['title' => "ME {$year}", 'url' => "?view=teams&year={$year}&day={$day}"];
    }
    
    if ($classFilter !== '') {
        $className = class_map_name($classFilter);
        $breadcrumbs[] = ['title' => "Klasa {$className}", 'url' => "?view=teams&year={$year}&day={$day}&class={$classFilter}"];
    }
    
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <title>Zespoły ME <?php echo $day ? "– {$day}" : ''; ?> | <?php echo e($config['app']['name']); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Ranking zespołów Mistrzostw Europy F-Class <?php echo $day ? "z dnia {$day}" : "roku {$year}"; ?>">
        
        <!-- Bootstrap CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="../assets/style.css">
        
        <style>
            .teams-table {
                font-size: 0.9rem;
            }
            .teams-table td, .teams-table th {
                padding: 0.5rem 0.3rem;
                vertical-align: middle;
            }
            .team-row:hover {
                background-color: rgba(13, 110, 253, 0.05);
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: all 0.2s ease;
            }
            .member-info {
                font-size: 0.85rem;
            }
            .team-name {
                font-weight: 600;
                color: #0d6efd;
            }
            .score-highlight {
                background: linear-gradient(135deg, #28a745, #20c997);
                color: white;
                border-radius: 0.375rem;
                padding: 0.25rem 0.5rem;
                font-weight: 600;
            }
            .invalid-team {
                opacity: 0.6;
                background-color: #f8f9fa;
            }
            .stats-card {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .class-card {
                transition: all 0.3s ease;
            }
            .class-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            }
            .search-highlight {
                background-color: #fff3cd;
                padding: 0.1rem 0.2rem;
                border-radius: 0.2rem;
            }
            @media (max-width: 768px) {
                .teams-table {
                    font-size: 0.8rem;
                }
                .teams-table td, .teams-table th {
                    padding: 0.25rem 0.15rem;
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
                        <i class="bi bi-people-fill text-primary me-2"></i>
                        Zespoły – Mistrzostwa Europy
                    </h1>
                    <?php if ($eventMeta): ?>
                        <p class="text-muted mb-0">
                            <i class="bi bi-calendar-event me-1"></i>
                            <strong><?php echo e($eventMeta['formatted_date']); ?></strong>
                            | <?php echo e($eventMeta['opis']); ?>
                            
                            <?php if ($classFilter): ?>
                                | <strong>Klasa:</strong> <?php echo e(class_map_name($classFilter)); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php render_navigation('teams'); ?>
            </div>
            
            <!-- Breadcrumbs -->
            <?php render_breadcrumbs($breadcrumbs); ?>
            
            <!-- Statystyki -->
            <?php if ($showStats && !empty($stats)): ?>
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-people-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_teams']; ?></h3>
                                <p class="mb-0 small">Zespołów łącznie</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-check-circle-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['valid_teams']; ?></h3>
                                <p class="mb-0 small">Zespołów kompletnych</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-person-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['total_participants']; ?></h3>
                                <p class="mb-0 small">Uczestników</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="bi bi-trophy-fill fs-1 mb-2"></i>
                                <h3 class="mb-1"><?php echo $stats['score_ranges']['max'] ?? 0; ?></h3>
                                <p class="mb-0 small">Najlepszy wynik</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Formularz filtrowania -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" action="" class="row g-3 align-items-end">
                        <input type="hidden" name="view" value="teams">
                        <?php if ($showStats): ?>
                            <input type="hidden" name="stats" value="1">
                        <?php endif; ?>
                        
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
                        
                        <div class="col-md-4">
                            <label for="day" class="form-label">
                                <i class="bi bi-flag me-1"></i>Mistrzostwa Europy
                            </label>
                            <select id="day" name="day" class="form-select">
                                <?php if (empty($events)): ?>
                                    <option value="">Brak dostępnych ME</option>
                                <?php else: ?>
                                    <?php foreach ($events as $event): ?>
                                        <option value="<?php echo e($event['day']); ?>" 
                                                <?php echo ($event['day'] === $day) ? 'selected' : ''; ?>>
                                            <?php echo e($event['formatted_date'] . ' – ' . $event['opis']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="class" class="form-label">
                                <i class="bi bi-funnel me-1"></i>Klasa
                            </label>
                            <select id="class" name="class" class="form-select">
                                <option value="">Wszystkie</option>
                                <?php 
                                $availableClasses = sort_classes(array_keys($teamsData), 'numeric');
                                foreach ($availableClasses as $classId): 
                                    if (empty($teamsData[$classId]['teams'])) continue;
                                    $className = class_map_name($classId);
                                    $teamsCount = count($teamsData[$classId]['teams']);
                                ?>
                                    <option value="<?php echo e($classId); ?>" 
                                            <?php echo ($classId === $classFilter) ? 'selected' : ''; ?>>
                                        <?php echo e($className . " ({$teamsCount})"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="search" class="form-label">
                                <i class="bi bi-search me-1"></i>Szukaj
                            </label>
                            <input type="text" id="search" name="search" class="form-control" 
                                   placeholder="Nazwa zespołu..." value="<?php echo e($searchTerm); ?>">
                        </div>
                        
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        
                        <div class="col-md-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="stats" id="stats" 
                                       <?php echo $showStats ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <label class="form-check-label small" for="stats">
                                    Stats
                                </label>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Filtry aktywne -->
                    <?php if ($classFilter !== '' || $searchTerm !== ''): ?>
                        <div class="mt-3">
                            <span class="badge bg-secondary me-2">Aktywne filtry:</span>
                            <?php if ($classFilter !== ''): ?>
                                <span class="badge bg-primary me-2">
                                    Klasa: <?php echo e(class_map_name($classFilter)); ?>
                                    <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                                       class="text-white ms-1">×</a>
                                </span>
                            <?php endif; ?>
                            <?php if ($searchTerm !== ''): ?>
                                <span class="badge bg-info me-2">
                                    Szukaj: "<?php echo e($searchTerm); ?>"
                                    <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?>&class=<?php echo $classFilter; ?>" 
                                       class="text-white ms-1">×</a>
                                </span>
                            <?php endif; ?>
                            <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?>" 
                               class="btn btn-outline-secondary btn-sm">Wyczyść filtry</a>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Export buttons -->
                    <?php if ($day !== ''): ?>
                        <div class="mt-3 d-flex justify-content-end">
                            <?php 
                            $exportParams = ['day' => $day];
                            if ($classFilter) $exportParams['class'] = $classFilter;
                            render_export_buttons('export/teams.php', $exportParams);
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Informacja o zespołach -->
            <div class="alert alert-info d-flex align-items-start">
                <i class="bi bi-info-circle-fill me-2 mt-1"></i>
                <div>
                    <strong>Zespoły Mistrzostw Europy:</strong>
                    Każdy zespół składa się z <?php echo $config['teams']['min_members']; ?>-<?php echo $config['teams']['max_members']; ?> zawodników 
                    z tej samej klasy. Wynik zespołu to suma wyników wszystkich członków.
                    <br>
                    <small class="text-muted">
                        <strong>Ranking:</strong> 1. Wynik łączny, 2. Wynik lepszego zawodnika, 3. Wynik drugiego zawodnika, 4. Nazwa zespołu alfabetycznie
                    </small>
                </div>
            </div>
            
            <!-- Zespoły -->
            <?php if ($day === ''): ?>
                <?php render_no_data_message('Wybierz rok i Mistrzostwa Europy aby wyświetlić zespoły.'); ?>
            <?php elseif (empty($displayData)): ?>
                <?php render_no_data_message('Brak zespołów dla wybranych kryteriów.', 'warning'); ?>
            <?php else: ?>
                <?php 
                $sortedClasses = sort_classes(array_keys($displayData), 'numeric');
                
                foreach ($sortedClasses as $classKey):
                    $classData = $displayData[$classKey];
                    $teams = $classData['teams'];
                    if (empty($teams)) continue;
                    
                    $className = $classData['class_name'];
                    $teamsCount = count($teams);
                    $validTeamsCount = count(array_filter($teams, fn($t) => $t['valid_team']));
                ?>
                    <div class="card class-card mb-4 shadow-sm">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-people me-2"></i>
                                Klasa: <?php echo e($className); ?>
                            </h5>
                            <div>
                                <span class="badge bg-success me-1">
                                    <?php echo $validTeamsCount; ?> kompletnych
                                </span>
                                <span class="badge bg-light text-dark me-2">
                                    <?php echo $teamsCount; ?> łącznie
                                </span>
                                <button class="btn btn-sm btn-outline-light" 
                                        type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#teams-<?php echo e($classKey); ?>" 
                                        aria-expanded="true">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="collapse show" id="teams-<?php echo e($classKey); ?>">
                            <div class="table-responsive">
                                <table class="table teams-table table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 70px;" class="text-center">Miejsce</th>
                                            <th style="width: 200px;">Zespół</th>
                                            <th>Zawodnik 1</th>
                                            <th class="text-center" style="width: 100px;">Punkty 1</th>
                                            <th>Zawodnik 2</th>
                                            <th class="text-center" style="width: 100px;">Punkty 2</th>
                                            <th class="text-center" style="width: 120px;">Wynik zespołu</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teams as $team): ?>
                                            <tr class="team-row <?php echo $team['valid_team'] ? '' : 'invalid-team'; ?>">
                                                <td class="text-center">
                                                    <span class="badge <?php echo $team['valid_team'] ? 'bg-primary' : 'bg-secondary'; ?> fs-6">
                                                        <?php echo (int)$team['rank']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="team-name">
                                                        <?php echo t_highlight_search_term($team['team_name'], $searchTerm); ?>
                                                    </div>
                                                    <?php if (!$team['valid_team']): ?>
                                                        <small class="text-danger">
                                                            <i class="bi bi-exclamation-triangle me-1"></i>Niepełny zespół
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <!-- Członek 1 -->
                                                <td>
                                                    <div class="member-info">
                                                        <?php if ($team['member1']['valid']): ?>
                                                            <?php echo t_highlight_search_term($team['member1']['full_name'], $searchTerm); ?>
                                                            <br>
                                                            <small class="text-muted">ID: <?php echo e($team['member1']['id']); ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">– brak danych –</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($team['member1']['valid']): ?>
                                                        <span class="score-highlight">
                                                            <?php echo format_display_number($team['member1']['total'], 0); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">–</span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <!-- Członek 2 -->
                                                <td>
                                                    <div class="member-info">
                                                        <?php if ($team['member2']['valid']): ?>
                                                            <?php echo t_highlight_search_term($team['member2']['full_name'], $searchTerm); ?>
                                                            <br>
                                                            <small class="text-muted">ID: <?php echo e($team['member2']['id']); ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">– brak danych –</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($team['member2']['valid']): ?>
                                                        <span class="score-highlight">
                                                            <?php echo format_display_number($team['member2']['total'], 0); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">–</span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <!-- Wynik zespołu -->
                                                <td class="text-center">
                                                    <div class="fw-bold fs-5 text-primary">
                                                        <?php echo format_display_number($team['team_total'], 0); ?>
                                                    </div>
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
            if ($day !== '') {
                $footerInfo['Wydarzenie'] = $eventMeta['formatted_date'] ?? $day;
                $footerInfo['Klasy'] = count($displayData);
                $footerInfo['Zespoły'] = array_sum(array_map(fn($c) => count($c['teams']), $displayData));
                
                if (!empty($stats)) {
                    $footerInfo['Kompletne zespoły'] = $stats['valid_teams'];
                }
            }
            
            render_footer($footerInfo);
            ?>
            
            <!-- Debug info -->
            <?php 
            if ($config['app']['allow_debug']) {
                $debugStats = [
                    'view' => 'teams',
                    'year' => $year,
                    'day' => $day,
                    'class_filter' => $classFilter,
                    'search_term' => $searchTerm,
                    'show_stats' => $showStats,
                    'events_count' => count($events),
                    'classes_count' => count($displayData),
                    'total_teams' => array_sum(array_map(fn($c) => count($c['teams']), $displayData)),
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
                    // Reset day and class selection when year changes
                    const daySelect = document.getElementById('day');
                    const classSelect = document.getElementById('class');
                    if (daySelect) daySelect.selectedIndex = 0;
                    if (classSelect) classSelect.selectedIndex = 0;
                    this.form.submit();
                });
            }
            
            // Enhanced team row hover effects
            const teamRows = document.querySelectorAll('.team-row');
            teamRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                });
            });
            
            // Search functionality enhancement
            const searchInput = document.getElementById('search');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (this.value.length >= 2 || this.value.length === 0) {
                            this.form.submit();
                        }
                    }, 500); // Auto-search after 500ms pause
                });
            }
            
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
            
            // Team statistics tooltip
            const scoreElements = document.querySelectorAll('.score-highlight');
            scoreElements.forEach(element => {
                element.style.cursor = 'help';
                element.title = 'Suma punktów ze wszystkich dystansów';
            });
        });
        </script>
    </body>
    </html>
    <?php
}

/**
 * Filtruje zespoły według wyszukiwanej frazy
 * 
 * @param array $teamsData Dane zespołów
 * @param string $searchTerm Wyszukiwana fraza
 * @return array Przefiltrowane dane
 */
function t_filter_teams_by_search(array $teamsData, string $searchTerm): array {
    if ($searchTerm === '') {
        return $teamsData;
    }
    
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    $filtered = [];
    
    foreach ($teamsData as $classKey => $classData) {
        $filteredTeams = array_filter($classData['teams'], function($team) use ($searchLower) {
            // Szukaj w nazwie zespołu
            $teamName = mb_strtolower($team['team_name'], 'UTF-8');
            if (mb_strpos($teamName, $searchLower, 0, 'UTF-8') !== false) {
                return true;
            }
            
            // Szukaj w nazwiskach członków
            $member1Name = mb_strtolower($team['member1']['full_name'], 'UTF-8');
            $member2Name = mb_strtolower($team['member2']['full_name'], 'UTF-8');
            
            return mb_strpos($member1Name, $searchLower, 0, 'UTF-8') !== false ||
                   mb_strpos($member2Name, $searchLower, 0, 'UTF-8') !== false;
        });
        
        if (!empty($filteredTeams)) {
            $filtered[$classKey] = [
                'class_name' => $classData['class_name'],
                'teams' => array_values($filteredTeams),
            ];
        }
    }
    
    return $filtered;
}

/**
 * Podświetla wyszukiwane frazy w tekście
 * 
 * @param string $text Tekst do podświetlenia
 * @param string $searchTerm Wyszukiwana fraza
 * @return string Tekst z podświetlonymi frazami
 */
function t_highlight_search_term(string $text, string $searchTerm): string {
    if ($searchTerm === '' || $text === '') {
        return e($text);
    }
    
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    $textLower = mb_strtolower($text, 'UTF-8');
    
    $pos = mb_strpos($textLower, $searchLower, 0, 'UTF-8');
    if ($pos === false) {
        return e($text);
    }
    
    $before = mb_substr($text, 0, $pos, 'UTF-8');
    $match = mb_substr($text, $pos, mb_strlen($searchTerm, 'UTF-8'), 'UTF-8');
    $after = mb_substr($text, $pos + mb_strlen($searchTerm, 'UTF-8'), null, 'UTF-8');
    
    return e($before) . '<span class="search-highlight">' . e($match) . '</span>' . 
           t_highlight_search_term($after, $searchTerm);
}

/**
 * Oblicza statystyki dla przefiltrowanych danych
 * 
 * @param array $displayData Wyświetlane dane
 * @param array $originalStats Oryginalne statystyki
 * @return array Zaktualizowane statystyki
 */
function t_calculate_filtered_stats(array $displayData, array $originalStats): array {
    $stats = $originalStats;
    
    // Przelicz statystyki dla wyświetlanych danych
    $stats['total_classes'] = count($displayData);
    $stats['total_teams'] = 0;
    $stats['valid_teams'] = 0;
    $stats['total_participants'] = 0;
    
    $allScores = [];
    
    foreach ($displayData as $classData) {
        foreach ($classData['teams'] as $team) {
            $stats['total_teams']++;
            $stats['total_participants'] += 2;
            
            if ($team['valid_team']) {
                $stats['valid_teams']++;
            }
            
            $allScores[] = $team['team_total'];
        }
    }
    
    // Aktualizuj zakresy punktów
    if (!empty($allScores)) {
        $stats['score_ranges'] = [
            'min' => min($allScores),
            'max' => max($allScores),
            'average' => round(array_sum($allScores) / count($allScores), 1),
        ];
    }