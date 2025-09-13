<?php
require_once __DIR__ . '/db.php';

/** Validate YYYYMMDD */
function t_normalize_day(string $day): ?string {
  return preg_match('/^\d{8}$/', $day) ? $day : null;
}

/** Fetch only ME events for a year (EUROPEAN CHAMPIONSHIPS), with existing filters. */
function t_fetch_me_events_for_year(int $year): array {
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
      AND UPPER(opis) LIKE '%EUROPEAN CHAMPIONSHIPS%'
    ORDER BY data ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':min'=>$min, ':max'=>$max]);
  $rows = $stmt->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $day = (string)$r['data'];
    if (!t_normalize_day($day)) continue;
    $out[] = ['day'=>$day, 'opis'=>(string)$r['opis']];
  }
  return $out;
}

/**
 * Team results for a given ME day, grouped by class id.
 * Returns: [ class_id => ['class_name'=>string, 'teams'=>[...]] ]
 */
function t_fetch_teams_by_class(string $day): array {
  $dayTable = t_normalize_day($day);
  if ($dayTable === null) return [];

  $pdo = db();
  $sql = "
    SELECT
      t.tclass       AS class_id,
      t.team_name    AS team_name,
      t.id1          AS id1,
      r1.fname       AS id1_fname,
      r1.lname       AS id1_lname,
      (COALESCE(r1.res_d1,0)+COALESCE(r1.res_d2,0)+COALESCE(r1.res_d3,0)) AS id1_total,
      t.id2          AS id2,
      r2.fname       AS id2_fname,
      r2.lname       AS id2_lname,
      (COALESCE(r2.res_d1,0)+COALESCE(r2.res_d2,0)+COALESCE(r2.res_d3,0)) AS id2_total
    FROM teams t
    LEFT JOIN `{$dayTable}` r1 ON r1.id = t.id1 AND r1.class = t.tclass
    LEFT JOIN `{$dayTable}` r2 ON r2.id = t.id2 AND r2.class = t.tclass
    WHERE t.tdday = :day
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':day'=>$dayTable]);
  $rows = $stmt->fetchAll();

  $by = [];
  foreach ($rows as $r) {
    $cid = (string)($r['class_id'] ?? '');
    if ($cid === '') continue;
    if (!isset($by[$cid])) $by[$cid] = ['class_name' => (string)$cid, 'teams' => []];

    $id1t = (float)($r['id1_total'] ?? 0);
    $id2t = (float)($r['id2_total'] ?? 0);
    $by[$cid]['teams'][] = [
      'team_name' => (string)($r['team_name'] ?? ''),
      'id1' => (string)($r['id1'] ?? ''),
      'id1_fname' => (string)($r['id1_fname'] ?? ''),
      'id1_lname' => (string)($r['id1_lname'] ?? ''),
      'id1_total' => $id1t,
      'id2' => (string)($r['id2'] ?? ''),
      'id2_fname' => (string)($r['id2_fname'] ?? ''),
      'id2_lname' => (string)($r['id2_lname'] ?? ''),
      'id2_total' => $id2t,
      'team_total'=> $id1t + $id2t,
    ];
  }

  // sort classes by id
  ksort($by, SORT_NATURAL);

  // sort teams and assign ranks per class
  foreach ($by as $cid => &$group) {
    $teams = $group['teams'];
    usort($teams, function($a,$b){
      $ta = (float)($a['team_total'] ?? 0);
      $tb = (float)($b['team_total'] ?? 0);
      if ($ta !== $tb) return ($ta < $tb) ? 1 : -1; // desc
      $a1 = (float)($a['id1_total'] ?? 0);
      $b1 = (float)($b['id1_total'] ?? 0);
      if ($a1 !== $b1) return ($a1 < $b1) ? 1 : -1; // desc
      return strcmp((string)($a['team_name'] ?? ''), (string)($b['team_name'] ?? ''));
    });
    $rank = 0; $prev = null;
    foreach ($teams as $i => &$t) {
      $sig = json_encode([$t['team_total'],$t['id1_total'],$t['team_name']], JSON_UNESCAPED_UNICODE);
      if ($prev === null || $sig !== $prev) $rank = $i + 1;
      $t['rank'] = $rank;
      $prev = $sig;
    }
    unset($t);
    $group['teams'] = $teams;
  }
  unset($group);

  return $by;
}

/** Flatten for export */
function t_fetch_teams_flat(string $day): array {
  $by = t_fetch_teams_by_class($day);
  $flat = [];
  foreach ($by as $classId => $group) {
    foreach ($group['teams'] as $t) {
      $row = $t;
      $row['class_id'] = $classId;
      $flat[] = $row;
    }
  }
  return $flat;
}
