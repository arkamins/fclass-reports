<?php
function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $cfg = require __DIR__ . '/config.php';
  $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['password'], $cfg['db']['options']);
  return $pdo;
}
