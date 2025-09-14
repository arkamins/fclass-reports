<?php
/**
 * Widok zespołów Mistrzostw Europy
 * 
 * Renderuje interfejs do przeglądania zespołów ME z rankingiem,
 * filtrami, statystykami oraz opcjami eksportu.
 * 
 * @author FClass Report Team
 * @version 9.1.0
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
    require_once __DIR__ . '/../../app/classmap.php';
    
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
    
    // WAŻNE: Sprawdź czy dzień należy do wybranego roku
    if ($day !== '') {
        $dayYear = (int)substr($day, 0, 4);
        if ($dayYear !== $year) {
            $day = ''; // Reset dnia jeśli nie pasuje do roku
        } else {
            // Sprawdź czy dzień istnieje w wydarzeniach tego roku
            $dayExists = false;
            foreach ($events as $event) {
                if ($event['day'] === $day) {
                    $dayExists = true;
                    break;
                }
            }
            if (!$dayExists) {
                $day = ''; // Reset jeśli dzień nie istnieje w wydarzeniach
            }
        }
    }
    
    // Wybierz domyślny dzień jeśli nie podano lub został zresetowany
    if ($day === '' && !empty($events)) {
        $day = $events[count($events) - 1]['day']; // Ostatnie ME w wybranym roku
    }
    
    // Pobierz dane zespołów z dodatkowymi danymi o centralnych dziesiątkach
    $teamsData = [];
    $eventMeta = null;
    if ($day !== '') {
        $teamsData = t_fetch_teams_by_class_with_tens($day);
        
        // Znajdź metadane wydarzenia
        foreach ($events as $event) {
            if ($event['day'] === $day) {
                $eventMeta = $event;
                break;
            }
        }
        
        // FILTRUJ tylko kompletne zespoły (2 zawodników)
        foreach ($teamsData as $classKey => &$classData) {
            $classData['teams'] = array_filter($classData['teams'], function($team) {
                return $team['valid_team'] && 
                       $team['member1']['valid'] && 
                       $team['member2']['valid'];
            });
            // Re-index array after filtering
            $classData['teams'] = array_values($classData['teams']);
        }
        unset($classData);
        
        // Usuń klasy bez zespołów
        $teamsData = array_filter($teamsData, function($classData) {
            return !empty($classData['teams']);
        });
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
            /* Podobny styl jak w results_view.php */
            .teams-table {
                font-size: 0.9rem;
            }
            .teams-table td, .teams-table th {
                padding: 0.5rem 0.25rem;
                vertical-align: middle;
            }
            /* Stałe szerokości kolumn */
            .teams-table th:nth-child(1),
            .teams-table td:nth-child(1) { width: 80px; }  /* Miejsce */
            .teams-table th:nth-child(2),
            .teams-table td:nth-child(2) { width: 200px; } /* Zespół */
            .teams-table th:nth-child(3),
            .teams-table td:nth-child(3) { width: 180px; } /* Zawodnik 1 */
            .teams-table th:nth-child(4),
            .teams-table td:nth-child(4) { width: 80px; }  /* Punkty 1 */
            .teams-table th:nth-child(5),
            .teams-table td:nth-child(5) { width: 60px; }  /* X 1 */
            .teams-table th:nth-child(6),
            .teams-table td:nth-child(6) { width: 180px; } /* Zawodnik 2 */
            .teams-table th:nth-child(7),
            .teams-table td:nth-child(7) { width: 80px; }  /* Punkty 2 */
            .teams-table th:nth-child(8),
            .teams-table td:nth-child(8) { width: 60px; }  /* X 2 */
            .teams-table th:nth-child(9),
            .teams-table td:nth-child(9) { width: 100px; } /* Wynik zespołu */
            .teams-table th:nth-child(10),
            .teams-table td:nth-child(10) { width: 80px; } /* X zespołu */
            
            .separator-column {
                border-left: 2px solid #dee2e6 !important;
            }
            .team-row:hover {
                background-color: rgba(13, 110, 253, 0.1);
            }
            .member-info {
                font-size: 0.9rem;
                font-weight: 600;
            }
            .team-name {
                font-weight: 600;
            }
            .search-highlight {
                background-color: #fff3cd;
                padding: 0.1rem 0.2rem;
                border-radius: 0.2rem;
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
            /* Styl dla central dziesiątek */
            .tens-value {
                color: #28a745;
                font-weight: 600;
            }
            .team-total {
                background-color: rgba(13, 110, 253, 0.1);
                border-radius: 0.25rem;
                padding: 0.2rem 0.4rem;
            }
            @media (max-width: 768px) {
                .teams-table {
                    font-size: 0.8rem;
                }
                .teams-table td, .teams-table th {
                    padding: 0.25rem 0.1rem;
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
                    <?php elseif ($day === '' && !empty($events)): ?>
                        <p class="text-muted mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Wybierz Mistrzostwa Europy z listy poniżej
                        </p>
                    <?php else: ?>
                        <p class="text-muted mb-0">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            Brak Mistrzostw Europy w roku <?php echo $year; ?>
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
                    <form method="get" action="" class="row g-3 align-items-end" id="teamsFilterForm">
                        <input type="hidden" name="view" value="teams">
                        
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
                        
                        <div class="col-md-4">
                            <label for="day" class="form-label">
                                <i class="bi bi-flag me-1"></i>Mistrzostwa Europy
                            </label>
                            <select id="day" name="day" class="form-select">
                                <?php if (empty($events)): ?>
                                    <option value="">Brak dostępnych ME w roku <?php echo $year; ?></option>
                                <?php else: ?>
                                    <option value="">-- Wybierz ME --</option>
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
                                if (!empty($teamsData)) {
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
                                    <?php endforeach;
                                }
                                ?>
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
                            <button type="submit" class="btn btn-primary w-100" title="Szukaj">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        
                        <div class="col-md-1">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="stats" id="stats" value="1"
                                       <?php echo $showStats ? 'checked' : ''; ?>>
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
                                    <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?><?php echo $showStats ? '&stats=1' : ''; ?>" 
                                       class="text-white ms-1">×</a>
                                </span>
                            <?php endif; ?>
                            <?php if ($searchTerm !== ''): ?>
                                <span class="badge bg-info me-2">
                                    Szukaj: "<?php echo e($searchTerm); ?>"
                                    <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?><?php echo $classFilter ? '&class=' . $classFilter : ''; ?><?php echo $showStats ? '&stats=1' : ''; ?>" 
                                       class="text-white ms-1">×</a>
                                </span>
                            <?php endif; ?>
                            <a href="?view=teams&year=<?php echo $year; ?>&day=<?php echo $day; ?><?php echo $showStats ? '&stats=1' : ''; ?>" 
                               class="btn btn-outline-secondary btn-sm">Wyczyść filtry</a>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Export buttons -->
                    <?php if ($day !== ''): ?>
                        <div class="mt-3 d-flex justify-content-end">
                            <?php 
                            $exportParams = ['day' => $day];
                            if ($classFilter) $exportParams['class'] = $classFilter;
                            if ($searchTerm) $exportParams['search'] = $searchTerm;
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
                        <strong>Ranking:</strong> 1. Wynik łączny, 2. Suma centralnych dziesiątek (X), 3. Wynik lepszego zawodnika, 4. Nazwa zespołu
                    </small>
                </div>
            </div>
            
            <!-- Zespoły -->
            <?php if ($day === ''): ?>
                <?php if (empty($events)): ?>
                    <?php render_no_data_message('Brak Mistrzostw Europy w roku ' . $year . '. Wybierz inny rok.', 'warning'); ?>
                <?php else: ?>
                    <?php render_no_data_message('Wybierz Mistrzostwa Europy z listy powyżej aby wyświetlić zespoły.'); ?>
                <?php endif; ?>
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
                ?>
                    <div class="card class-card mb-4 shadow-sm">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-people me-2"></i>
                                Klasa: <?php echo e($className); ?>
                            </h5>
                            <div>
                                <span class="badge bg-light text-dark">
                                    <?php echo $teamsCount; ?> zespołów
                                </span>
                                <button class="btn btn-sm btn-outline-light ms-2" 
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
                                <table class="table teams-table table-striped table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center">Miejsce</th>
                                            <th>Zespół</th>
                                            <th class="separator-column">Zawodnik 1</th>
                                            <th class="text-center">Punkty</th>
                                            <th class="text-center">X</th>
                                            <th class="separator-column">Zawodnik 2</th>
                                            <th class="text-center">Punkty</th>
                                            <th class="text-center">X</th>
                                            <th class="text-end separator-column">Wynik</th>
                                            <th class="text-center">ΣX</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teams as $team): ?>
                                            <tr class="team-row">
                                                <td class="text-center">
                                                    <span class="badge bg-primary fs-6">
                                                        <?php echo (int)$team['rank']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="team-name">
                                                        <?php echo t_highlight_search_term($team['team_name'], $searchTerm); ?>
                                                    </div>
                                                </td>
                                                
                                                <!-- Zawodnik 1 -->
                                                <td class="separator-column">
                                                    <div class="member-info">
                                                        <?php echo t_highlight_search_term($team['member1']['full_name'], $searchTerm); ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo format_display_number($team['member1']['total'], 0); ?>
                                                </td>
                                                <td class="text-center tens-value">
                                                    <?php echo format_display_number($team['member1']['tens'] ?? 0, 0); ?>
                                                </td>
                                                
                                                <!-- Zawodnik 2 -->
                                                <td class="separator-column">
                                                    <div class="member-info">
                                                        <?php echo t_highlight_search_term($team['member2']['full_name'], $searchTerm); ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo format_display_number($team['member2']['total'], 0); ?>
                                                </td>
                                                <td class="text-center tens-value">
                                                    <?php echo format_display_number($team['member2']['tens'] ?? 0, 0); ?>
                                                </td>
                                                
                                                <!-- Wynik zespołu -->
                                                <td class="text-end fw-bold separator-column">
                                                    <span class="team-total">
                                                        <?php echo format_display_number($team['team_total'], 0); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center fw-bold tens-value">
                                                    <?php echo format_display_number($team['team_tens'] ?? 0, 0); ?>
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
            
            <!-- Footer - uproszczony -->
            <?php render_footer([]); ?>
            
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
            // Auto-reload form on year change
            const yearSelect = document.getElementById('year');
            if (yearSelect) {
                yearSelect.addEventListener('change', function() {
                    // Build clean URL with only year and view
                    const params = new URLSearchParams();
                    params.append('view', 'teams');
                    params.append('year', this.value);
                    
                    // Preserve stats if checked
                    const statsCheckbox = document.getElementById('stats');
                    if (statsCheckbox && statsCheckbox.checked) {
                        params.append('stats', '1');
                    }
                    
                    // Redirect with clean parameters - no day, class or search
                    window.location.href = '?' + params.toString();
                });
            }
            
            // Auto-reload form on day change
            const daySelect = document.getElementById('day');
            if (daySelect) {
                daySelect.addEventListener('change', function() {
                    if (this.value) {
                        // Submit form when day is selected
                        document.getElementById('teamsFilterForm').submit();
                    }
                });
            }
            
            // Auto-reload form on stats checkbox change
            const statsCheckbox = document.getElementById('stats');
            if (statsCheckbox) {
                statsCheckbox.addEventListener('change', function() {
                    document.getElementById('teamsFilterForm').submit();
                });
            }
            
            // Form submission - clean empty parameters
            const mainForm = document.getElementById('teamsFilterForm');
            if (mainForm) {
                mainForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Collect only non-empty values
                    const formData = new FormData(this);
                    const params = new URLSearchParams();
                    
                    for (let [key, value] of formData.entries()) {
                        // Skip empty values except for checkboxes
                        if (value && value.trim() !== '') {
                            params.append(key, value.trim());
                        }
                    }
                    
                    // Always include view
                    params.set('view', 'teams');
                    
                    // Redirect with clean URL
                    window.location.href = '?' + params.toString();
                });
            }
            
            // Enhanced team row hover effects
            const teamRows = document.querySelectorAll('.team-row');
            teamRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'rgba(13, 110, 253, 0.1)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
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
            
            // Tooltip dla centralnych dziesiątek
            const tensElements = document.querySelectorAll('.tens-value');
            tensElements.forEach(element => {
                element.style.cursor = 'help';
                element.title = 'Centralne dziesiątki (X-ring hits)';
            });
        });
        </script>
    </body>
    </html>
    <?php
}

/**
 * Pobiera zespoły z danymi o centralnych dziesiątkach
 * 
 * @param string $day Dzień wydarzenia
 * @return array Dane zespołów z centralymi dziesiątkami
 */
function t_fetch_teams_by_class_with_tens(string $day): array {
    $normalizedDay = t_normalize_day($day);
    if ($normalizedDay === null) {
        return [];
    }
    
    $cacheKey = "teams_by_class_tens_{$normalizedDay}";
    $cached = cache_get($cacheKey, 600);
    
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $rawTeams = t_fetch_raw_teams_data_with_tens($normalizedDay);
        $processedTeams = t_process_teams_data_with_tens($rawTeams);
        
        if (!empty($processedTeams)) {
            cache_set($cacheKey, $processedTeams);
        }
        
        return $processedTeams;
        
    } catch (Exception $e) {
        error_log("Error fetching teams with tens for day {$day}: " . $e->getMessage());
        return [];
    }
}

/**
 * Pobiera surowe dane zespołów z centralnymi dziesiątkami
 * 
 * @param string $day Znormalizowany dzień wydarzenia
 * @return array Surowe dane zespołów
 */
function t_fetch_raw_teams_data_with_tens(string $day): array {
    if (!validateTableName($day)) {
        throw new InvalidArgumentException("Invalid table name: {$day}");
    }
    
    if (!tableExists($day)) {
        throw new RuntimeException("Event table does not exist: {$day}");
    }
    
    $pdo = db();
    
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
    
    return array_map('clean_for_db', $rows);
}

/**
 * Przetwarza surowe dane zespołów z centralnymi dziesiątkami
 * 
 * @param array $rawTeams Surowe dane zespołów
 * @return array Przetworzone zespoły z rankingiem
 */
function t_process_teams_data_with_tens(array $rawTeams): array {
    $teamsByClass = [];
    
    foreach ($rawTeams as $row) {
        $classId = (string)($row['class_id'] ?? '');
        if ($classId === '') continue;
        
        if (!isset($teamsByClass[$classId])) {
            $teamsByClass[$classId] = [
                'class_name' => class_map_name($classId),
                'teams' => [],
            ];
        }
        
        $team = t_build_team_data_with_tens($row);
        if ($team !== null) {
            $teamsByClass[$classId]['teams'][] = $team;
        }
    }
    
    // Sortuj klasy i przypisz rangi
    $sortedClasses = sort_classes(array_keys($teamsByClass), 'numeric');
    $result = [];
    
    foreach ($sortedClasses as $classId) {
        $classData = $teamsByClass[$classId];
        $sortedTeams = t_sort_and_rank_teams_with_tens($classData['teams']);
        
        $result[$classId] = [
            'class_name' => $classData['class_name'],
            'teams' => $sortedTeams,
        ];
    }
    
    return $result;
}

/**
 * Buduje dane pojedynczego zespołu z centralnymi dziesiątkami
 * 
 * @param array $row Wiersz danych z bazy
 * @return array|null Dane zespołu lub null jeśli niepoprawne
 */
function t_build_team_data_with_tens(array $row): ?array {
    $teamName = trim((string)($row['team_name'] ?? ''));
    if ($teamName === '') return null;
    
    $member1Total = (float)($row['member1_total'] ?? 0);
    $member1Tens = (int)($row['member1_tens'] ?? 0);
    $member2Total = (float)($row['member2_total'] ?? 0);
    $member2Tens = (int)($row['member2_tens'] ?? 0);
    
    $teamTotal = $member1Total + $member2Total;
    $teamTens = $member1Tens + $member2Tens;
    
    $member1 = t_validate_team_member([
        'id' => (string)($row['member1_id'] ?? ''),
        'fname' => trim((string)($row['member1_fname'] ?? '')),
        'lname' => trim((string)($row['member1_lname'] ?? '')),
        'total' => $member1Total,
        'tens' => $member1Tens,
    ]);
    
    $member2 = t_validate_team_member([
        'id' => (string)($row['member2_id'] ?? ''),
        'fname' => trim((string)($row['member2_fname'] ?? '')),
        'lname' => trim((string)($row['member2_lname'] ?? '')),
        'total' => $member2Total,
        'tens' => $member2Tens,
    ]);
    
    return [
        'team_name' => $teamName,
        'member1' => $member1,
        'member2' => $member2,
        'team_total' => $teamTotal,
        'team_tens' => $teamTens,
        'valid_team' => ($member1['valid'] && $member2['valid']),
    ];
}

/**
 * Sortuje zespoły i przypisuje rangi z uwzględnieniem centralnych dziesiątek
 * 
 * @param array $teams Lista zespołów
 * @return array Posortowane zespoły z rangami
 */
function t_sort_and_rank_teams_with_tens(array $teams): array {
    if (empty($teams)) return [];
    
    // Sortuj według wyników zespołu z uwzględnieniem centralnych dziesiątek
    usort($teams, function($a, $b) {
        // 1. Wynik łączny zespołu (DESC)
        if ($a['team_total'] !== $b['team_total']) {
            return $b['team_total'] <=> $a['team_total'];
        }
        
        // 2. Suma centralnych dziesiątek zespołu (DESC)
        if ($a['team_tens'] !== $b['team_tens']) {
            return $b['team_tens'] <=> $a['team_tens'];
        }
        
        // 3. Wynik lepszego zawodnika (DESC)
        $aBest = max($a['member1']['total'], $a['member2']['total']);
        $bBest = max($b['member1']['total'], $b['member2']['total']);
        
        if ($aBest !== $bBest) {
            return $bBest <=> $aBest;
        }
        
        // 4. Centralne dziesiątki lepszego zawodnika (DESC)
        $aBestTens = ($a['member1']['total'] > $a['member2']['total']) ? 
                      $a['member1']['tens'] : $a['member2']['tens'];
        $bBestTens = ($b['member1']['total'] > $b['member2']['total']) ? 
                      $b['member1']['tens'] : $b['member2']['tens'];
        
        if ($aBestTens !== $bBestTens) {
            return $bBestTens <=> $aBestTens;
        }
        
        // 5. Alfabetycznie po nazwie zespołu
        return strcmp($a['team_name'], $b['team_name']);
    });
    
    // Przypisz rangi
    $rank = 1;
    $previousSignature = null;
    
    foreach ($teams as $index => &$team) {
        $signature = json_encode([
            $team['team_total'],
            $team['team_tens'],
            max($team['member1']['total'], $team['member2']['total']),
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
            $member1Name = mb_strtolower($team['member1']['full_name'] ?? '', 'UTF-8');
            $member2Name = mb_strtolower($team['member2']['full_name'] ?? '', 'UTF-8');
            
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
    
    $safeText = e($text);
    $safeSearchTerm = e($searchTerm);
    
    $pattern = '/(' . preg_quote($safeSearchTerm, '/') . ')/iu';
    $replacement = '<span class="search-highlight">$1</span>';
    
    $highlighted = @preg_replace($pattern, $replacement, $safeText, 10);
    
    if ($highlighted === null) {
        return $safeText;
    }
    
    return $highlighted;
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
    
    if (!empty($allScores)) {
        $stats['score_ranges'] = [
            'min' => min($allScores),
            'max' => max($allScores),
            'average' => round(array_sum($allScores) / count($allScores), 1),
        ];
    } else {
        $stats['score_ranges'] = [
            'min' => 0,
            'max' => 0,
            'average' => 0,
        ];
    }
    
    return $stats;
}
?>