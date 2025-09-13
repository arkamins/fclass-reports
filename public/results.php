<?php
require_once __DIR__ . '/../app/event_results.php';
require_once __DIR__ . '/../app/encoding.php';
require_once __DIR__ . '/../app/classmap.php';

header('Content-Type: text/html; charset=utf-8');

$cfgYear = (int)date('Y');
$maxYear = er_fetch_max_year();
$year = isset($_GET['year']) ? (int)$_GET['year'] : $cfgYear;
if ($year < 2000) $year = 2000;
if ($year > $maxYear) $year = $maxYear;

$events = er_fetch_events_for_year($year);
$day = isset($_GET['day']) ? (string)$_GET['day'] : '';
if ($day === '' && !empty($events)) {
  $day = $events[count($events)-1]['day']; // default last
}

$built = ['meta'=>['day'=>$day,'opis'=>''],'tables'=>[]];
if ($day !== '') $built = er_build_event_tables($day);
$meta = $built['meta']; $tables = $built['tables'];

function class_map_name_local($k){ return class_map_name($k); }

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
    .cell-empty { color:#999; }
    .subhdr { font-size: .85rem; color:#666; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 w-100">
    <h1 class="mb-0">Wyniki zawodów</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="index.php?view=ranking">Ranking</a>
      <a class="btn btn-primary" href="results.php">Wyniki zawodów</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get" action="results.php">
    <div class="col-auto">
      <label for="year" class="form-label">Rok</label>
      <select id="year" name="year" class="form-select" onchange="this.form.submit()">
        <?php for ($y=2000; $y <= $maxYear; $y++): ?>
          <option value="<?php echo $y; ?>" <?php echo ($y===$year?'selected':''); ?>><?php echo $y; ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <label for="day" class="form-label">Zawody</label>
      <select id="day" name="day" class="form-select">
        <?php foreach ($events as $ev): ?>
          <option value="<?php echo e($ev['day']); ?>" <?php echo ($ev['day']===$day?'selected':''); ?>><?php echo e($ev['day'].' – '.$ev['opis']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-primary">Pokaż</button>
    </div>
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
    <?php else: ?>
      <span>Wybierz rok i zawody.</span>
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
      <div class="card-header bg-dark text-white">Klasa: <?php echo e(class_map_name_local($classKey)); ?></div>
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead>
            <tr>
              <th style="width: 90px;">Miejsce</th>
              <th>Zawodnik</th>
              <th colspan="3" class="text-center">300 m</th>
              <th colspan="3" class="text-center">600 m</th>
              <th colspan="3" class="text-center">800 m</th>
              <th class="text-end">Wynik łączny</th>
            </tr>
            <tr class="text-muted">
              <th></th><th></th>
              <th>Wynik</th><th>X</th><th>MOA</th>
              <th>Wynik</th><th>X</th><th>MOA</th>
              <th>Wynik</th><th>X</th><th>MOA</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="badge bg-primary fs-6"><?php echo (int)$r['rank']; ?></span></td>
              <td class="fw-semibold"><?php echo e(trim(($r['fname'] ?? '').' '.($r['lname'] ?? ''))); ?></td>
              <?php
                $cells = [
                  ['res'=>'res_d1','x'=>'x_d1','moa'=>'moa_d1'],
                  ['res'=>'res_d2','x'=>'x_d2','moa'=>'moa_d2'],
                  ['res'=>'res_d3','x'=>'x_d3','moa'=>'moa_d3'],
                ];
                foreach ($cells as $c) {
                  $res = isset($r[$c['res']]) ? (int)$r[$c['res']] : null;
                  $x   = isset($r[$c['x']]) ? (int)$r[$c['x']] : null;
                  $moa = isset($r[$c['moa']]) ? $r[$c['moa']] : null;
                  echo '<td>'.($res===null?'–':number_format($res,0,',',' ')).'</td>';
                  echo '<td>'.($x===null?'–':number_format($x,0,',',' ')).'</td>';
                  echo '<td>'.($moa===null?'–':htmlspecialchars((string)$moa, ENT_QUOTES, 'UTF-8')).'</td>';
                }
              ?>
              <td class="text-end fw-bold"><?php echo number_format((int)$r['total'], 0, ',', ' '); ?></td>
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
