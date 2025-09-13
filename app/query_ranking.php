<?php
require_once __DIR__ . '/db.php';

/** YYYYMMDD table name */
function normalize_day_table(string $day): ?string {
  return preg_match('/^\d{8}$/', $day) ? $day : null;
}

function fetch_max_year(): int {
  $pdo = db();
  $row = $pdo->query("SELECT MAX(data) AS maxd FROM zawody")->fetch();
  if (!$row || !$row['maxd']) return (int)date('Y');
  $maxd = (int)$row['maxd'];
  $y = (int)floor($maxd / 10000);
  if ($y < 2000) $y = 2000;
  return $y;
}

/** Select events for a given year, with your filters */
function fetch_events_for_year(int $year): array {
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
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':min'=>$min, ':max'=>$max]);
  $rows = $stmt->fetchAll();
  $events = [];
  foreach ($rows as $r) {
    $day = (string)$r['data'];
    $tbl = normalize_day_table($day);
    if ($tbl === null) continue;
    $opis = (string)$r['opis'];
    $is_me = (stripos($opis, 'EUROPEAN CHAMPIONSHIPS') !== false);
    $events[] = ['day'=>$day, 'table'=>$tbl, 'is_me'=>$is_me];
  }
  usort($events, function($a,$b){ return strcmp($a['day'],$b['day']); });
  return $events;
}

/** Aggregate per event by (class, fname, lname); ignore empty names */
function fetch_event_results(string $table): array {
  $pdo = db();
  $sql = "
    SELECT
      class,
      TRIM(COALESCE(fname,'')) AS fname,
      TRIM(COALESCE(lname,'')) AS lname,
      COALESCE(SUM(COALESCE(res_d1,0) + COALESCE(res_d2,0) + COALESCE(res_d3,0)),0) AS total
    FROM `{$table}`
    WHERE TRIM(COALESCE(fname,'')) <> '' AND TRIM(COALESCE(lname,'')) <> ''
    GROUP BY class, TRIM(COALESCE(fname,'')), TRIM(COALESCE(lname,''))
  ";
  $rows = $pdo->query($sql)->fetchAll();
  $byClass = [];
  foreach ($rows as $r) {
    $cls = (string)$r['class'];
    $fn  = (string)$r['fname'];
    $ln  = (string)$r['lname'];
    if (!isset($byClass[$cls])) $byClass[$cls] = [];
    $byClass[$cls][] = [
      'key'   => strtolower($fn).'|'.strtolower($ln),
      'fname' => $fn,
      'lname' => $ln,
      'total' => (float)$r['total'],
    ];
  }
  return $byClass;
}

/**
 * Build annual ranking:
 * - identity by (lower(trim(fname))|lower(trim(lname))) within class and year
 * - qualification: >=1 ME start with score>0 AND >=2 non-ME starts with score>0
 */
function build_annual_ranking_with_columns(int $year): array {
  $events = fetch_events_for_year($year);
  if (empty($events)) return ['events'=>[], 'data'=>[]];

  // load all events
  $allEventsResults = [];
  foreach ($events as $ev) {
    $allEventsResults[$ev['day']] = fetch_event_results($ev['table']);
  }

  // collect players by class and name-key
  $players = [];
  foreach ($events as $ev) {
    $day = $ev['day']; $is_me = $ev['is_me'];
    $byClass = $allEventsResults[$day] ?? [];
    foreach ($byClass as $class => $peopleRows) {
      foreach ($peopleRows as $rec) {
        $key = $rec['key'];
        if (!isset($players[$class])) $players[$class] = [];
        if (!isset($players[$class][$key])) {
          $players[$class][$key] = ['fname'=>$rec['fname'],'lname'=>$rec['lname'],'scores'=>[],'days'=>[]];
        }
        $score = (float)$rec['total'];
        $players[$class][$key]['scores'][$day] = $score;
        $players[$class][$key]['days'][] = ['day'=>$day,'is_me'=>$is_me,'score'=>$score];
      }
    }
  }

  // columns: all days
  $allDays = array_map(function($e){return $e['day'];}, $events);
  sort($allDays, SORT_STRING);

  $data = [];
  foreach ($players as $class => $people) {
    $rows = [];
    foreach ($people as $p) {
      // split & filter only positive scores for qualification
      $meArr = []; $otherArr = [];
      foreach ($p['days'] as $d) {
        if ($d['score'] > 0) {
          if ($d['is_me']) $meArr[] = $d; else $otherArr[] = $d;
        }
      }
      if (empty($meArr) || count($otherArr) < 2) continue;

      // choose best ME (if multiple) and best two non-ME
      usort($meArr, function($a,$b){ return ($a['score']<$b['score'])?1:(($a['score']>$b['score'])?-1:0); });
      usort($otherArr, function($a,$b){ return ($a['score']<$b['score'])?1:(($a['score']>$b['score'])?-1:0); });
      $mePick = $meArr[0]; $best1 = $otherArr[0]; $best2 = $otherArr[1];
      $total = (float)$mePick['score'] + (float)$best1['score'] + (float)$best2['score'];

      $selectedDays = [$mePick['day']=>true, $best1['day']=>true, $best2['day']=>true];
      $cells = [];
      foreach ($allDays as $d) {
        $val = isset($p['scores'][$d]) ? (float)$p['scores'][$d] : null;
        $cells[$d] = ['score'=>$val, 'selected'=> ($val !== null && isset($selectedDays[$d])) ];
      }

      $rows[] = [
        'fname'=>$p['fname'],'lname'=>$p['lname'],
        'cells'=>$cells, 'total'=>$total, 'me'=>(float)$mePick['score'],
        'best1'=>(float)$best1['score'],'best2'=>(float)$best2['score'],
        'starts'=>count($meArr) + count($otherArr)
      ];
    }

    // sort and rank
    usort($rows, function($a,$b){
      if ($a['total'] == $b['total']) {
        if ($a['me'] == $b['me']) {
          if ($a['best1'] == $b['best1']) return strcmp($a['lname'].$a['fname'],$b['lname'].$b['fname']);
          return ($a['best1'] < $b['best1']) ? 1 : -1;
        }
        return ($a['me'] < $b['me']) ? 1 : -1;
      }
      return ($a['total'] < $b['total']) ? 1 : -1;
    });
    $rank = 0; $prev = null;
    foreach ($rows as $i => $r) {
      $s = $r['total'];
      if ($prev === null || $s < $prev) $rank = $i + 1;
      $rows[$i]['rank'] = $rank;
      $prev = $s;
    }
    $data[$class] = $rows;
  }

  return ['events'=>$allDays, 'data'=>$data];
}
