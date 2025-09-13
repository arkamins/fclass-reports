<?php
function to_utf8($v) {
  $cfg = require __DIR__ . '/config.php';
  $src = $cfg['app']['source_charset'] ?? 'utf8';
  if (is_array($v)) { foreach ($v as $k=>$x) $v[$k] = to_utf8($x); return $v; }
  if ($v === null) return '';
  if (is_string($v)) {
    if ($src === 'latin2') return @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-2');
    return $v;
  }
  return $v;
}
function e($v) { return htmlspecialchars(to_utf8($v), ENT_QUOTES, 'UTF-8'); }
