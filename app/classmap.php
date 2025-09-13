<?php
function class_map_name($classKey) {
  $map = [
    '1' => 'FTR',
    '2' => 'Open',
    '3' => 'Magnum',
    '4' => 'Semi-Auto',
    '5' => 'Semi-Auto Open',
    '6' => 'Sniper',
    '7' => 'Sniper Open',
	'8' => 'Ultra Magnum',
  ];
  $k = (string)$classKey;
  return isset($map[$k]) ? $map[$k] : $k;
}
