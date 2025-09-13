<?php
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
date_default_timezone_set('Europe/Warsaw');

return [
  'db' => [
    'dsn'      => 'mysql:host=sql.laserowytrening.home.pl;port=3306;dbname=laserowytrening2;charset=utf8mb4',
    'user'     => 'laserowytrening2',
    'password' => 'laserowyf-class',
    'options'  => [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
  ],
  'app' => [
    'year' => 2025,
    'cache_ttl' => 300,
    'source_charset' => 'utf8',    // set to 'latin2' if DB stores ISO-8859-2 bytes
    'max_event_columns' => 4       // max number of event columns
  ],
];
