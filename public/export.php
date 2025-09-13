<?php
require_once __DIR__ . '/../app/query_ranking.php';
require_once __DIR__ . '/../app/encoding.php';
require_once __DIR__ . '/../app/classmap.php';

$cfg = require __DIR__ . '/../app/config.php';
$year = (int)($_GET['year'] ?? $cfg['app']['year']);
$fmt = strtolower((string)($_GET['fmt'] ?? 'csv'));

$res = build_annual_ranking_with_columns($year);
$events = $res['events'] ?? [];
$data   = $res['data']   ?? [];
$data   = to_utf8($data);
$filtered = array();
foreach ($data as $classKey => $rows) {
  if (!empty($rows)) $filtered[$classKey] = $rows;
}

if ($fmt === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['events'=>$events, 'data'=>$filtered], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="annual_ranking_' . $year . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$header = ['class','rank','fname','lname'];
foreach ($events as $d) $header[] = $d;
$header[] = 'total';
fputcsv($out, $header);
foreach ($filtered as $classKey => $rows) {
  $className = class_map_name($classKey);
  foreach ($rows as $r) {
    $row = [$className, $r['rank'] ?? '', $r['fname'] ?? '', $r['lname'] ?? ''];
    foreach ($events as $d) {
      if (!isset($r['cells'][$d]) || $r['cells'][$d]['score'] === null) $row[] = '';
      else $row[] = (float)$r['cells'][$d]['score'];
    }
    $row[] = (float)($r['total'] ?? 0);
    fputcsv($out, $row);
  }
}
fclose($out);
