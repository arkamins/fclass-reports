<?php
require_once __DIR__ . '/../app/teams.php';

$fmt = strtolower((string)($_GET['fmt'] ?? 'csv'));
$day = isset($_GET['day']) ? (string)$_GET['day'] : '';

$rows = ($day!=='') ? t_fetch_teams_flat($day) : [];

if ($fmt === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="teams_'+($day!==''?$day:'data')+'.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['rank','class_id','team_name','id1','id1_fname','id1_lname','id1_total','id2','id2_fname','id2_lname','id2_total','team_total'], ';', '"');
foreach ($rows as $r) {
  fputcsv($out, [
    $r['rank'] ?? '',
    $r['class_id'] ?? '',
    $r['team_name'] ?? '',
    $r['id1'] ?? '',
    $r['id1_fname'] ?? '',
    $r['id1_lname'] ?? '',
    $r['id1_total'] ?? 0,
    $r['id2'] ?? '',
    $r['id2_fname'] ?? '',
    $r['id2_lname'] ?? '',
    $r['id2_total'] ?? 0,
    $r['team_total'] ?? 0,
  ], ';', '"');
}
fclose($out);
