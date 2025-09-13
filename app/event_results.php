<?php
require_once __DIR__ . '/db.php';

/** YYYYMMDD validation */
function er_normalize_day_table(string $day): ?string {
  return preg_match('/^\d{8}$/', $day) ? $day : null;
}

function er_fetch_max_year(): int {
  $pdo = db();
  $row = $pdo->query("SELECT MAX(data) AS maxd FROM zawody")->fetch();
  if (!$row || !$row['maxd']) return (int)date('Y');
  $maxd = (int)$row['maxd'];
  $y = (int)floor($maxd / 10000);
  if ($y < 2016) $y = 2016;
  return $y;
}

function er_fetch_events_for_year(int $year): array {
  $pdo = db();
  $min = (int)($year . '0000');
  $max = (int)($year . '9999');
  $sql = "
    SELECT data, opis
    FROM zawody
    WHERE data BETWEEN :min AND :max
      AND opis IS NOT NULL
      AND TRIM(opis) <> ''
      AND LOWER(opis) NOT LIKE '%22lr%'
      AND LOWER(opis) NOT LIKE '%test%'
    ORDER BY data ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':min'=>$min, ':max'=>$max]);
  $rows = $stmt->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $day = (string)$r['data'];
    $tbl = er_normalize_day_table($day);
    if ($tbl === null) continue;
    $out[] = ['day'=>$day, 'opis'=>(string)$r['opis']];
  }
  return $out;
}

function er_fetch_results_for_event(string $table): array {
  $pdo = db();
  $sql = "
    SELECT
      class,
      COALESCE(TRIM(fname),'') AS fname,
      COALESCE(TRIM(lname),'') AS lname,
      COALESCE(res_d1,0) AS res_d1,
      COALESCE(res_d2,0) AS res_d2,
      COALESCE(res_d3,0) AS res_d3,
      COALESCE(x_d1,0)   AS x_d1,
      COALESCE(x_d2,0)   AS x_d2,
      COALESCE(x_d3,0)   AS x_d3,
      COALESCE(moa_d1,NULL) AS moa_d1,
      COALESCE(moa_d2,NULL) AS moa_d2,
      COALESCE(moa_d3,NULL) AS moa_d3
    FROM `{$table}`
  ";
  return $pdo->query($sql)->fetchAll();
}

/**
 * Build per-class tables with stable ranks:
 * - Ranks are computed by BASE ORDER ONLY: total DESC, sum_x DESC, avg_moa ASC, lname, fname.
 * - Display order can be changed by $sort/$dir, but 'rank' stays as computed above.
 */
function er_build_event_tables(string $day, ?string $sort = null, string $dir = 'desc'): array {
  $tbl = er_normalize_day_table($day);
  if ($tbl === null) return ['meta'=>['day'=>$day,'opis'=>''],'tables'=>[]];

  $rows = er_fetch_results_for_event($tbl);

  $classes = [];
  foreach ($rows as $r) {
    $class = (string)($r['class'] ?? '');
    $fname = (string)($r['fname'] ?? '');
    $lname = (string)($r['lname'] ?? '');
    if ($fname === '' && $lname === '') continue;

    if (!isset($classes[$class])) $classes[$class] = [];
    $key = strtolower($fname).'|'.strtolower($lname);
    if (!isset($classes[$class][$key])) {
      $classes[$class][$key] = [
        'fname'=>$fname, 'lname'=>$lname,
        'res_d1'=>(int)$r['res_d1'], 'res_d2'=>(int)$r['res_d2'], 'res_d3'=>(int)$r['res_d3'],
        'x_d1'  =>(int)$r['x_d1'],   'x_d2'  =>(int)$r['x_d2'],   'x_d3'  =>(int)$r['x_d3'],
        'moa_d1'=>$r['moa_d1'], 'moa_d2'=>$r['moa_d2'], 'moa_d3'=>$r['moa_d3'],
      ];
    } else {
      $classes[$class][$key]['res_d1'] += (int)$r['res_d1'];
      $classes[$class][$key]['res_d2'] += (int)$r['res_d2'];
      $classes[$class][$key]['res_d3'] += (int)$r['res_d3'];
      $classes[$class][$key]['x_d1']   += (int)$r['x_d1'];
      $classes[$class][$key]['x_d2']   += (int)$r['x_d2'];
      $classes[$class][$key]['x_d3']   += (int)$r['x_d3'];
      foreach (['moa_d1','moa_d2','moa_d3'] as $mk) {
        $old = $classes[$class][$key][$mk];
        $new = $r[$mk];
        if ($old === null) $classes[$class][$key][$mk] = $new;
        elseif ($new !== null) $classes[$class][$key][$mk] = min((float)$old, (float)$new);
      }
    }
  }

  $allowedSort = [
    'total'=>true, 'avg_moa'=>true,
    'res_d1'=>true,'x_d1'=>true,'moa_d1'=>true,
    'res_d2'=>true,'x_d2'=>true,'moa_d2'=>true,
    'res_d3'=>true,'x_d3'=>true,'moa_d3'=>true,
  ];
  $sort = ($sort !== null && isset($allowedSort[$sort])) ? $sort : 'total';
  $dir = (strtolower($dir) === 'asc') ? 'asc' : 'desc';
  $mult = ($dir === 'asc') ? 1 : -1;

  $tables = [];
  foreach ($classes as $classKey => $people) {
    $arr = array_values($people);
    foreach ($arr as &$p) {
      $p['total'] = (int)$p['res_d1'] + (int)$p['res_d2'] + (int)$p['res_d3'];
      $p['sum_x'] = (int)$p['x_d1'] + (int)$p['x_d2'] + (int)$p['x_d3'];
      $m1 = $p['moa_d1']; $m2 = $p['moa_d2']; $m3 = $p['moa_d3'];
      $p['avg_moa'] = ($m1 !== null && $m2 !== null && $m3 !== null) ? (((float)$m1 + (float)$m2 + (float)$m3) / 3.0) : null;
    }
    unset($p);

    $arr = array_values(array_filter($arr, function($r){ return (int)$r['total'] > 0; }));

    // BASE ORDER for ranks
    usort($arr, function($a,$b){
      if ($a['total'] !== $b['total']) return ($a['total'] < $b['total']) ? 1 : -1;
      if ($a['sum_x'] !== $b['sum_x']) return ($a['sum_x'] < $b['sum_x']) ? 1 : -1;
      $am = $a['avg_moa']; $bm = $b['avg_moa'];
      if ($am === null && $bm !== null) return 1;
      if ($bm === null && $am !== null) return -1;
      if ($am !== null && $bm !== null && $am != $bm) return ($am < $bm) ? -1 : 1;
      $ln = strcmp($a['lname'], $b['lname']);
      if ($ln !== 0) return $ln;
      return strcmp($a['fname'], $b['fname']);
    });
    $rank = 0; $prevSig = null;
    foreach ($arr as $i => &$r) {
      $sig = json_encode([$r['total'],$r['sum_x'],$r['avg_moa'],$r['lname'],$r['fname']], JSON_UNESCAPED_UNICODE);
      if ($prevSig === null || $sig !== $prevSig) $rank = $i + 1;
      $r['rank'] = $rank;
      $prevSig = $sig;
    }
    unset($r);

    // DISPLAY ORDER with corrected comparator:
    usort($arr, function($a,$b) use ($sort,$mult){
      $va = $a[$sort] ?? null; $vb = $b[$sort] ?? null;
      $isNullA = ($va === null); $isNullB = ($vb === null);
      if ($isNullA !== $isNullB) return $isNullA ? 1 : -1; // nulls last
      if ($va !== $vb) {
        // Correct sign: for DESC ($mult=-1), va>vb -> -1 (a before b)
        return ($va < $vb) ? (-1 * $mult) : (1 * $mult);
      }
      $ln = strcmp($a['lname'], $b['lname']);
      if ($ln !== 0) return $ln;
      return strcmp($a['fname'], $b['fname']);
    });

    $tables[$classKey] = $arr;
  }

  $meta = ['day'=>$day, 'opis'=>''];
  $pdo = db();
  $stmt = $pdo->prepare("SELECT opis FROM zawody WHERE data = :d LIMIT 1");
  $stmt->execute([':d'=>(int)$day]);
  $row = $stmt->fetch();
  if ($row && isset($row['opis'])) $meta['opis'] = (string)$row['opis'];

  return ['meta'=>$meta, 'tables'=>$tables];
}
