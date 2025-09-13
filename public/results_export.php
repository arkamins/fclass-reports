<?php
require_once __DIR__ . '/../app/event_results.php';
require_once __DIR__ . '/../app/classmap.php';

/** resolve 'day' parameter; supports 'last' */
function er_resolve_day($dayParam) {
  if ($dayParam !== 'last') return $dayParam;
  // try from max year down to 2000
  $year = er_fetch_max_year();
  for ($y = $year; $y >= 2000; $y--) {
    $evs = er_fetch_events_for_year($y);
    if (!empty($evs)) {
      $last = $evs[count($evs)-1];
      return $last['day'];
    }
  }
  return '';
}

$dayParam = isset($_GET['day']) ? (string)$_GET['day'] : '';
$fmt = strtolower((string)($_GET['fmt'] ?? 'csv'));

if ($dayParam === '') {
  header('HTTP/1.1 400 Bad Request');
  echo 'Missing day parameter';
  exit;
}

if ($dayParam === 'last' && $fmt === 'json') {
  // return only meta for last event (for index.html bootstrap)
  $year = er_fetch_max_year();
  $meta = ['day' => '', 'opis' => ''];
  for ($y = $year; $y >= 2000; $y--) {
    $evs = er_fetch_events_for_year($y);
    if (!empty($evs)) {
      $last = $evs[count($evs)-1];
      $meta['day'] = $last['day'];
      $meta['opis'] = $last['opis'];
      break;
    }
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['meta'=>$meta], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// otherwise export full data for resolved day
$day = er_resolve_day($dayParam);
if ($day === '') {
  header('HTTP/1.1 404 Not Found');
  echo 'No events found';
  exit;
}

$built = er_build_event_tables($day);
$tables = $built['tables'];

if ($fmt === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  $out = [];
  foreach ($tables as $classKey => $rows) {
    $className = class_map_name($classKey);
    foreach ($rows as $r) {
      $out[] = [
        'class' => $className,
        'rank'  => (int)$r['rank'],
        'fname' => (string)$r['fname'],
        'lname' => (string)$r['lname'],
        'res_d1'=> (int)$r['res_d1'], 'x_d1'=>(int)$r['x_d1'], 'moa_d1'=>$r['moa_d1'],
        'res_d2'=> (int)$r['res_d2'], 'x_d2'=>(int)$r['x_d2'], 'moa_d2'=>$r['moa_d2'],
        'res_d3'=> (int)$r['res_d3'], 'x_d3'=>(int)$r['x_d3'], 'moa_d3'=>$r['moa_d3'],
        'total' => (int)$r['total'],
      ];
    }
  }
  echo json_encode(['meta'=> $built['meta'], 'rows'=> $out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="event_results_' . $day . '.csv'");
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['class','rank','fname','lname','res_d1','x_d1','moa_d1','res_d2','x_d2','moa_d2','res_d3','x_d3','moa_d3','total']);
foreach ($tables as $classKey => $rows) {
  $className = class_map_name($classKey);
  foreach ($rows as $r) {
    fputcsv($out, [
      $className, (int)$r['rank'], (string)$r['fname'], (string)$r['lname'],
      (int)$r['res_d1'], (int)$r['x_d1'], $r['moa_d1'],
      (int)$r['res_d2'], (int)$r['x_d2'], $r['moa_d2'],
      (int)$r['res_d3'], (int)$r['x_d3'], $r['moa_d3'],
      (int)$r['total']
    ]);
  }
}
fclose($out);
