<?php
// public/debug.php - stwórz ten plik tymczasowo
require_once __DIR__ . '/../app/cache.php';

echo "<h3>Debug Cache</h3>";
echo "<p><strong>Cache directory:</strong> " . getCacheDirectory() . "</p>";
echo "<p><strong>sys_get_temp_dir():</strong> " . sys_get_temp_dir() . "</p>";

$stats = cache_stats();
echo "<p><strong>Cache enabled:</strong> " . ($stats['enabled'] ? 'YES' : 'NO') . "</p>";
echo "<p><strong>Cache files:</strong> " . $stats['files'] . "</p>";
echo "<p><strong>Cache total size:</strong> " . formatBytes($stats['total_size']) . "</p>";

// Sprawdź czy katalog istnieje
$cacheDir = getCacheDirectory();
echo "<p><strong>Directory exists:</strong> " . (is_dir($cacheDir) ? 'YES' : 'NO') . "</p>";

if (is_dir($cacheDir)) {
    echo "<h4>Cache files:</h4><ul>";
    $files = glob($cacheDir . '/*/*.json');
    foreach ($files as $file) {
        echo "<li>" . basename($file) . " (" . date('Y-m-d H:i:s', filemtime($file)) . ")</li>";
    }
    echo "</ul>";
}

// Test cache
echo "<h4>Cache Test:</h4>";
cache_set('test_key', ['test' => 'data']);
$test = cache_get('test_key', 3600);
echo "<p>Cache test: " . (($test && $test['test'] === 'data') ? 'WORKING' : 'NOT WORKING') . "</p>";

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>