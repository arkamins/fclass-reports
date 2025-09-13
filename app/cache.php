<?php
function cache_get(string $key, int $ttl): ?array {
  $file = sys_get_temp_dir() . '/ranking_cache_' . md5($key) . '.json';
  if (!is_file($file)) return null;
  if (filemtime($file) + $ttl < time()) return null;
  $json = file_get_contents($file);
  $data = json_decode($json, true);
  return is_array($data) ? $data : null;
}
function cache_set(string $key, array $value): void {
  $file = sys_get_temp_dir() . '/ranking_cache_' . md5($key) . '.json';
  file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
