<?php
// public/index.php — WYNIKI / RANKING / ZESPOŁY (pełny, scalony)
header('Content-Type: text/html; charset=utf-8');

$view = isset($_GET['view']) ? strtolower((string)$_GET['view']) : 'results';
$MIN_YEAR = 2020;

// wspólne include’y
require_once __DIR__ . '/../app/encoding.php';
require_once __DIR__ . '/../app/classmap.php';

// helper: nawigacja
function render_nav($active) {
  echo '<div class="d-flex gap-2">';
  echo '<a class="btn '.($active==='ranking'?'btn-primary':'btn-outline-secondary').'" href="index.php?view=ranking">Ranking</a>';
  echo '<a class="btn '.($active==='results'?'btn-primary':'btn-outline-secondary').'" href="index.php?view=results">Wyniki</a>';
  echo '<a class="btn '.($active==='teams'?'btn-primary':'btn-outline-secondary').'" href="index.php?view=teams">Zespoły</a>';
  echo '</div>';
}

///////////////////////////////////////////////////////////////
// WIDOK ZESPOŁY (ME tylko)
///////////////////////////////////////////////////////////////
if ($view === 'teams') {
  require_once __DIR__ . '/../app/db.php';
  require_once __DIR__ . '/../app/teams.php';         // z paczki teams
  require_once __DIR__ . '/../app/event_results.php'; // dla er_fetch_max_year()

  $maxYear = er_fetch_max_year();
  if ($maxYear < $MIN_YEAR) $maxYear = $MIN_YEAR;

  $year = isset($_GET['year']) ? (int)$_GET['year'] : $maxYear;
  if ($year < $MIN_YEAR) $year = $MIN_YEAR;
  if ($year > $maxYear)   $year = $maxYear;

  $events = t_fetch_me_events_for_year($year);
  $day = isset($_GET['day']) ? (string)$_GET['day'] : '';
  if ($day === '') {
    if (!empty($events)) $day = $events[count($events)-1]['day'];
    else {
      for ($y=$year-1; $y >= $MIN_YEAR; $y--) {
        $evs = t_fetch_me_events_for_year($y);
        if (!empty($evs)) { $day = $evs[count($evs)-1]['day']; $year = $y; $events = $evs; break; }
      }
    }
  }

  $by = ($day!=='') ? t_fetch_teams_by_class($day) : [];

  ?>
  <!doctype html>
  <html lang="pl">
  <head>
    <meta charset="utf-8">
    <title>Zespoły – <?php echo e($day); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>.cell-empty{color:#999}</style>
  </head>
  <body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 w-100">
      <h1 class="mb-0">Zespoły – Mistrzostwa Europy</h1>
      <?php render_nav('teams'); ?>
    </div>

    <form class="row g-2 align-items-end mb-3" method="get" action="">
      <input type="hidden" name="view" value="teams">
      <div class="col-auto">
        <label for="year" class="form-label">Rok</label>
        <select id="year" name="year" class="form-select" onchange="this.form.submit()">
          <?php for ($y=$MIN_YEAR; $y <= $maxYear; $y++): ?>
            <option value="<?php echo $y; ?>" <?php echo ($y===$year?'selected':''); ?>><?php echo $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-auto">
        <label for="day" class="form-label">Zawody (ME)</label>
        <select id="day" name="day" class="form-select">
          <?php foreach ($events as $ev): ?>
            <option value="<?php echo e($ev['day']); ?>" <?php echo ($ev['day']===$day?'selected':''); ?>>
              <?php echo e($ev['day'].' – '.$ev['opis']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-primary">Pokaż</button></div>
      <div class="col-auto ms-auto">
        <?php if ($day !== ''): ?>
          <a class="btn btn-outline-secondary" href="teams_export.php?day=<?php echo urlencode((string)$day); ?>&fmt=csv">Eksport CSV</a>
          <a class="btn btn-outline-secondary" href="teams_export.php?day=<?php echo urlencode((string)$day); ?>&fmt=json">Eksport JSON</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($day === '' || empty($by)): ?>
      <div class="alert alert-warning">Brak danych zespołów dla wybranego roku / zawodów ME.</div>
    <?php else: foreach ($by as $classId => $group): if (empty($group['teams'])) continue; ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-dark text-white">Klasa: <?php echo e(class_map_name($classId)); ?></div>
    <div class="table-responsive">
     <table class="table table-striped align-middle mb-0" style="table-layout: fixed; width:100%;">
        <colgroup>
               <col style="width: 90px;">
               <col style="width: 18%;">
               <col style="width: 18%;">
               <col style="width: 10%;">
               <col style="width: 18%;">
               <col style="width: 10%;">
               <col style="width: 10%;">
         </colgroup>
    <thead>
      <tr>
               <th>Miejsce</th>
               <th>Zespół</th>
               <th>Zawodnik 1</th>
               <th>Punkty 1</th>
               <th>Zawodnik 2</th>
               <th>Punkty 2</th>
               <th>Razem</th>
      </tr>
    </thead>
            <tbody>
              <?php foreach ($group['teams'] as $t): ?>
              <tr>
                <td><span class="badge bg-primary fs-6"><?php echo (int)($t['rank'] ?? 0); ?></span></td>
                <td class="fw-semibold"><?php echo e((string)($t['team_name'] ?? '')); ?></td>
                <td><?php echo e(trim((string)($t['id1_fname'] ?? '').' '.(string)($t['id1_lname'] ?? ''))); ?></td>
                <td><?php echo number_format((float)($t['id1_total'] ?? 0), 0, ',', ' '); ?></td>
                <td><?php echo e(trim((string)($t['id2_fname'] ?? '').' '.(string)($t['id2_lname'] ?? ''))); ?></td>
                <td><?php echo number_format((float)($t['id2_total'] ?? 0), 0, ',', ' '); ?></td>
                <td class="fw-bold"><?php echo number_format((float)($t['team_total'] ?? 0), 0, ',', ' '); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <footer class="text-muted small mt-4">Generowane: <?php echo date('Y-m-d H:i:s'); ?> CET/CEST</footer>
  </div>
  </body>
  </html>
  <?php
  exit;
}

///////////////////////////////////////////////////////////////
// WIDOK RANKING
///////////////////////////////////////////////////////////////
if ($view === 'ranking') {
  require_once __DIR__ . '/../app/query_ranking.php';
  require_once __DIR__ . '/../app/cache.php';

  $cfg = require __DIR__ . '/../app/config.php';

  $maxYear = fetch_max_year();
  if ($maxYear < $MIN_YEAR) $maxYear = $MIN_YEAR;
  $selected = isset($_GET['year']) ? (int)$_GET['year'] : (int)($cfg['app']['year'] ?? date('Y'));
  if ($selected < $MIN_YEAR) $selected = $MIN_YEAR;
  if ($selected > $maxYear)   $selected = $maxYear;

  $cacheKey = 'v9_ranking_cols:' . $selected;
  $cached = cache_get($cacheKey, (int)($cfg['app']['cache_ttl'] ?? 300));
  if ($cached) {
    $events = $cached['events'] ?? [];
    $data   = $cached['data']   ?? [];
  } else {
    $res = build_annual_ranking_with_columns($selected);
    $events = $res['events'] ?? [];
    $data   = $res['data']   ?? [];
    $data   = to_utf8($data);
    cache_set($cacheKey, ['events'=>$events, 'data'=>$data]);
  }
  $filtered = [];
  foreach ($data as $classKey => $rows) if (!empty($rows)) $filtered[$classKey] = $rows;
  ?>
  <!doctype html>
  <html lang="pl">
  <head>
    <meta charset="utf-8">
    <title>Ranking – <?php echo e($selected); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>.cell-selected{font-weight:700}.cell-empty{color:#999}</style>
  </head>
  <body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 w-100">
      <h1 class="mb-0">Roczny ranking – <?php echo e($selected); ?></h1>
      <?php render_nav('ranking'); ?>
    </div>

    <form class="d-flex align-items-center mb-3" method="get" action="">
      <input type="hidden" name="view" value="ranking">
      <label for="year" class="me-2">Rok:</label>
      <select id="year" name="year" class="form-select me-2" style="max-width:160px">
        <?php for ($y=$MIN_YEAR;$y<=$maxYear;$y++): ?>
        <option value="<?php echo $y; ?>" <?php echo ($y===$selected?'selected':''); ?>><?php echo $y; ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-primary">Pokaż</button>
      <div class="ms-auto d-flex gap-2">
        <a class="btn btn-outline-secondary" href="export.php?year=<?php echo urlencode((string)$selected); ?>&fmt=csv">Eksport CSV</a>
        <a class="btn btn-outline-secondary" href="export.php?year=<?php echo urlencode((string)$selected); ?>&fmt=json">Eksport JSON</a>
      </div>
    </form>

    <?php if (empty($filtered)): ?>
      <div class="alert alert-warning">Brak danych do wyświetlenia.</div>
    <?php else: foreach ($filtered as $classKey => $rows): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-dark text-white">Klasa: <?php echo e(class_map_name($classKey)); ?></div>
        <div class="table-responsive">
          <table class="table table-striped align-middle mb-0">
            <thead>
              <tr>
                <th style="width: 90px;">Miejsce</th>
                <th>Zawodnik</th>
                <?php foreach ($events as $d): ?><th><?php echo e($d); ?></th><?php endforeach; ?>
                <th class="text-end">Wynik roczny</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><span class="badge bg-primary fs-6"><?php echo (int)($r['rank'] ?? 0); ?></span></td>
                <td class="fw-semibold"><?php echo e(trim(($r['fname'] ?? '').' '.($r['lname'] ?? ''))); ?></td>
                <?php foreach ($events as $d):
                  $cell = $r['cells'][$d] ?? null;
                  if (!$cell || $cell['score'] === null) {
                    echo '<td class="cell-empty">–</td>';
                  } else {
                    $val = number_format((float)$cell['score'], 2, ',', ' ');
                    $cls = $cell['selected'] ? 'cell-selected' : '';
                    echo '<td class="'.$cls.'">'.$val.'</td>';
                  }
                endforeach; ?>
                <td class="fw-bold text-end"><?php echo number_format((float)($r['total'] ?? 0), 2, ',', ' '); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <footer class="text-muted small mt-4">Generowane: <?php echo date('Y-m-d H:i:s'); ?> CET/CEST</footer>
  </div>
  </body>
  </html>
  <?php
  exit;
}

///////////////////////////////////////////////////////////////
// WIDOK WYNIKI (domyślny)
///////////////////////////////////////////////////////////////
require_once __DIR__ . '/../app/event_results.php';

$maxYear = er_fetch_max_year();
if ($maxYear < $MIN_YEAR) $maxYear = $MIN_YEAR;

$year = isset($_GET['year']) ? (int)$_GET['year'] : $maxYear;
if ($year < $MIN_YEAR) $year = $MIN_YEAR;
if ($year > $maxYear)   $year = $maxYear;

$events = er_fetch_events_for_year($year);
$day = isset($_GET['day']) ? (string)$_GET['day'] : '';
if ($day === '') {
  $picked = '';
  if (!empty($events)) $picked = $events[count($events)-1]['day'];
  else {
    for ($y = $year - 1; $y >= $MIN_YEAR; $y--) {
      $evs = er_fetch_events_for_year($y);
      if (!empty($evs)) { $picked = $evs[count($evs)-1]['day']; $year = $y; $events = $evs; break; }
    }
  }
  $day = $picked;
}

$allowedSort = [
  'total'=>true,'avg_moa'=>true,
  'res_d1'=>true,'x_d1'=>true,'moa_d1'=>true,
  'res_d2'=>true,'x_d2'=>true,'moa_d2'=>true,
  'res_d3'=>true,'x_d3'=>true,'moa_d3'=>true,
];
$sort = (isset($_GET['sort']) && isset($allowedSort[$_GET['sort']])) ? $_GET['sort'] : 'total';
$dir  = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc';

$built = ['meta'=>['day'=>$day,'opis'=>''],'tables'=>[]];
if ($day !== '') $built = er_build_event_tables($day, $sort, $dir);
$meta = $built['meta']; $tables = $built['tables'];

function sort_link($label, $key, $currentSort, $currentDir, $year, $day) {
  $nextDir = ($currentSort === $key && $currentDir === 'desc') ? 'asc' : 'desc';
  $icon = '';
  if ($currentSort === $key) $icon = $currentDir === 'desc' ? ' ↓' : ' ↑';
  $url = 'index.php?view=results&year='.urlencode((string)$year).'&day='.urlencode((string)$day).'&sort='.$key.'&dir='.$nextDir;
  return '<a href="'.$url.'" class="text-decoration-none">'.$label.$icon.'</a>';
}
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Wyniki zawodów – <?php echo e($meta['day']); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .cell-empty{color:#999}.subhdr{font-size:.85rem;color:#666}
    .sep-left { border-left: 2px solid #dee2e6 !important; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 w-100">
    <h1 class="mb-0">Wyniki zawodów</h1>
    <?php render_nav('results'); ?>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get" action="">
    <input type="hidden" name="view" value="results">
    <div class="col-auto">
      <label for="year" class="form-label">Rok</label>
      <select id="year" name="year" class="form-select" onchange="this.form.submit()">
        <?php for ($y=$MIN_YEAR; $y <= $maxYear; $y++): ?>
          <option value="<?php echo $y; ?>" <?php echo ($y===$year?'selected':''); ?>><?php echo $y; ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <label for="day" class="form-label">Zawody</label>
      <select id="day" name="day" class="form-select">
        <?php foreach ($events as $ev): ?>
          <option value="<?php echo e($ev['day']); ?>" <?php echo ($ev['day']===$day?'selected':''); ?>>
            <?php echo e($ev['day'].' – '.$ev['opis']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-primary">Pokaż</button></div>
    <div class="col-auto ms-auto">
      <?php if ($day !== ''): ?>
        <a class="btn btn-outline-secondary" href="results_export.php?day=<?php echo urlencode((string)$day); ?>&fmt=csv">Eksport CSV</a>
        <a class="btn btn-outline-secondary" href="results_export.php?day=<?php echo urlencode((string)$day); ?>&fmt=json">Eksport JSON</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="mb-3 subhdr">
    <?php if ($day !== ''): ?>
      <span><strong>Data:</strong> <?php echo e($meta['day']); ?></span> &nbsp;|&nbsp;
      <span><strong>Opis:</strong> <?php echo e($meta['opis']); ?></span>
      &nbsp;|&nbsp; <span><strong>Sort:</strong> <?php echo e($sort); ?> (<?php echo e(strtoupper($dir)); ?>)</span>
    <?php else: ?>
      <span>Brak dostępnych zawodów — wybierz inny rok.</span>
    <?php endif; ?>
  </div>

  <?php if ($day === ''): ?>
    <div class="alert alert-info">Brak wybranych zawodów.</div>
  <?php else: ?>
    <?php
      $keys = array_keys($tables);
      usort($keys, function($a,$b){
        $ia = (int)$a; $ib = (int)$b;
        if ((string)$ia === $a && (string)$ib === $b) return $ia <=> $ib;
        return strcmp($a,$b);
      });
      foreach ($keys as $classKey):
        $rows = $tables[$classKey];
        if (empty($rows)) continue;
    ?>
    <div class="card mb-4 shadow-sm">
      <div class="card-header bg-dark text-white">Klasa: <?php echo e(class_map_name($classKey)); ?></div>
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead>
            <tr>
              <th style="width: 90px;">Miejsce</th>
              <th>Zawodnik</th>
              <th colspan="3" class="text-center sep-left">300 m</th>
              <th colspan="3" class="text-center sep-left">600 m</th>
              <th colspan="3" class="text-center sep-left">800 m</th>
              <th class="text-center sep-left"><?php echo sort_link('Śr. MOA','avg_moa',$sort,$dir,$year,$day); ?></th>
              <th class="text-end sep-left"><?php echo sort_link('Wynik łączny','total',$sort,$dir,$year,$day); ?></th>
            </tr>
            <tr class="text-muted">
              <th></th><th></th>
              <th class="sep-left"><?php echo sort_link('Wynik','res_d1',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('X','x_d1',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('MOA','moa_d1',$sort,$dir,$year,$day); ?></th>

              <th class="sep-left"><?php echo sort_link('Wynik','res_d2',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('X','x_d2',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('MOA','moa_d2',$sort,$dir,$year,$day); ?></th>

              <th class="sep-left"><?php echo sort_link('Wynik','res_d3',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('X','x_d3',$sort,$dir,$year,$day); ?></th>
              <th><?php echo sort_link('MOA','moa_d3',$sort,$dir,$year,$day); ?></th>

              <th class="sep-left"></th>
              <th class="sep-left"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="badge bg-primary fs-6"><?php echo (int)$r['rank']; ?></span></td>
              <td class="fw-semibold"><?php echo e(trim(($r['fname'] ?? '').' '.($r['lname'] ?? ''))); ?></td>
              <?php
                $cells = [
                  ['res'=>'res_d1','x'=>'x_d1','moa'=>'moa_d1','cls'=>'sep-left'],
                  ['res'=>'res_d2','x'=>'x_d2','moa'=>'moa_d2','cls'=>'sep-left'],
                  ['res'=>'res_d3','x'=>'x_d3','moa'=>'moa_d3','cls'=>'sep-left'],
                ];
                foreach ($cells as $c) {
                  $res = isset($r[$c['res']]) ? (int)$r[$c['res']] : null;
                  $x   = isset($r[$c['x']]) ? (int)$r[$c['x']] : null;
                  $moa = isset($r[$c['moa']]) ? $r[$c['moa']] : null;
                  echo '<td class="'.$c['cls'].'">'.($res===null?'–':number_format($res,0,',',' ')).'</td>';
                  echo '<td>'.($x===null?'–':number_format($x,0,',',' ')).'</td>';
                  echo '<td>'.($moa===null?'–':htmlspecialchars((string)$moa, ENT_QUOTES, 'UTF-8')).'</td>';
                }
                $avg = isset($r['avg_moa']) ? $r['avg_moa'] : null;
                echo '<td class="text-center sep-left">'.($avg===null?'':number_format((float)$avg, 3, ',', ' ')).'</td>';
              ?>
              <td class="text-end fw-bold sep-left"><?php echo number_format((int)$r['total'], 0, ',', ' '); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <footer class="text-muted small mt-4">Generowane: <?php echo date('Y-m-d H:i:s'); ?> CET/CEST</footer>
</div>
</body>
</html>
