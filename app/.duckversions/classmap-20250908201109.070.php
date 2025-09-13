<?php
function class_map_name($classKey) {
  $map = [
    '1' => 'FTR',
    '2' => 'Open',
    '3' => 'Magnum',
    '4' => 'SemiAuto',
    '5' => 'SemiAuto Open',
    '6' => 'Sniper',
    '7' => 'Sniper Open',
  ];
  $k = (string)$classKey;
  return isset($map[$k]) ? $map[$k] : $k;
}
