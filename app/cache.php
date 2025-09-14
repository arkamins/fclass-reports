<?php
/**
 * System zarządzania cache
 * 
 * Efektywny system cache'owania dla aplikacji FClass Report.
 * Obsługuje różne typy danych, TTL, invalidację oraz cleanup.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

/**
 * Pobiera dane z cache
 * 
 * Bezpiecznie pobiera dane z cache z walidacją TTL i integrity check.
 * Automatycznie usuwa przestarzałe wpisy.
 * 
 * @param string $key Klucz cache
 * @param int|null $ttl TTL w sekundach (null = użyj domyślnego z config)
 * @return mixed|null Dane z cache lub null jeśli nie ma/przestarzałe
 */
function cache_get(string $key, ?int $ttl = null): mixed {
    $config = require __DIR__ . '/config.php';
    
    // Sprawdź czy cache jest włączony
    if (!$config['app']['cache_enabled']) {
        return null;
    }
    
    // Użyj domyślnego TTL jeśli nie podano
    if ($ttl === null) {
        $ttl = $config['app']['cache_ttl'];
    }
    
    // Sanityzuj klucz
    $safeKey = sanitizeCacheKey($key);
    $filename = getCacheFilename($safeKey);
    
    // Sprawdź czy plik istnieje
    if (!is_file($filename)) {
        return null;
    }
    
    try {
        // Sprawdź wiek pliku
        $fileTime = filemtime($filename);
        if (!$fileTime || ($fileTime + $ttl) < time()) {
            // Plik przestarzały - usuń go
            @unlink($filename);
            return null;
        }
        
        // Wczytaj zawartość
        $content = file_get_contents($filename);
        if ($content === false) {
            return null;
        }
        
        // Dekoduj JSON
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Uszkodzony plik cache - usuń go
            @unlink($filename);
            return null;
        }
        
        // Waliduj strukturę
        if (!isset($data['timestamp'], $data['data'], $data['checksum'])) {
            @unlink($filename);
            return null;
        }
        
        // Sprawdź checksum dla integrity
        $expectedChecksum = generateChecksum($data['data']);
        if ($data['checksum'] !== $expectedChecksum) {
            // Dane uszkodzone - usuń cache
            @unlink($filename);
            return null;
        }
        
        return $data['data'];
        
    } catch (Exception $e) {
        error_log("Cache read error for key '$key': " . $e->getMessage());
        // Usuń problematyczny plik
        @unlink($filename);
        return null;
    }
}

/**
 * Zapisuje dane do cache
 * 
 * Bezpiecznie zapisuje dane do cache z metadanymi i checksumą.
 * Automatycznie tworzy strukturę katalogów jeśli potrzeba.
 * 
 * @param string $key Klucz cache
 * @param mixed $data Dane do zapisania
 * @param array $metadata Dodatkowe metadane
 * @return bool True jeśli sukces, false w przypadku błędu
 */
function cache_set(string $key, mixed $data, array $metadata = []): bool {
    $config = require __DIR__ . '/config.php';
    
    // Sprawdź czy cache jest włączony
    if (!$config['app']['cache_enabled']) {
        return false;
    }
    
    $safeKey = sanitizeCacheKey($key);
    $filename = getCacheFilename($safeKey);
    
    try {
        // Utwórz katalog jeśli nie istnieje
        $dir = dirname($filename);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log("Could not create cache directory: $dir");
            return false;
        }
        
        // Przygotuj dane do zapisania
        $cacheData = [
            'timestamp' => time(),
            'key' => $key,
            'data' => $data,
            'checksum' => generateChecksum($data),
            'metadata' => array_merge($metadata, [
                'php_version' => PHP_VERSION,
                'app_version' => $config['app']['version'],
                'created_by' => 'cache_set',
            ]),
        ];
        
        // Encode do JSON z pretty print w trybie debug
        $flags = $config['export']['json']['flags'];
        if ($config['app']['allow_debug']) {
            $flags |= JSON_PRETTY_PRINT;
        }
        
        $json = json_encode($cacheData, $flags);
        if ($json === false) {
            error_log("JSON encode error for cache key '$key': " . json_last_error_msg());
            return false;
        }
        
        // Zapisz atomowo (poprzez temp file)
        $tempFile = $filename . '.tmp.' . uniqid();
        if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
            error_log("Could not write cache temp file: $tempFile");
            return false;
        }
        
        // Atomowe przeniesienie
        if (!rename($tempFile, $filename)) {
            @unlink($tempFile);
            error_log("Could not move cache temp file to final location");
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Cache write error for key '$key': " . $e->getMessage());
        // Cleanup w przypadku błędu
        @unlink($tempFile ?? '');
        return false;
    }
}

/**
 * Usuwa wpis z cache
 * 
 * @param string $key Klucz do usunięcia
 * @return bool True jeśli usunięto lub nie istniał
 */
function cache_delete(string $key): bool {
    $safeKey = sanitizeCacheKey($key);
    $filename = getCacheFilename($safeKey);
    
    if (is_file($filename)) {
        return @unlink($filename);
    }
    
    return true; // Nie istniał = sukces
}

/**
 * Czyści przestarzałe wpisy cache
 * 
 * Usuwa wszystkie pliki cache starsze niż podany TTL.
 * Przydatne do okresowego sprzątania.
 * 
 * @param int|null $maxAge Maksymalny wiek w sekundach (null = domyślny TTL)
 * @return int Liczba usuniętych plików
 */
function cache_cleanup(?int $maxAge = null): int {
    $config = require __DIR__ . '/config.php';
    
    if ($maxAge === null) {
        $maxAge = $config['app']['cache_ttl'];
    }
    
    $cacheDir = getCacheDirectory();
    $cutoffTime = time() - $maxAge;
    $deleted = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $mtime = $file->getMTime();
                if ($mtime && $mtime < $cutoffTime) {
                    if (@unlink($file->getPathname())) {
                        $deleted++;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Cache cleanup error: " . $e->getMessage());
    }
    
    return $deleted;
}

/**
 * Czyści cały cache
 * 
 * Usuwa wszystkie pliki cache. Używaj ostrożnie!
 * 
 * @return int Liczba usuniętych plików
 */
function cache_clear(): int {
    $cacheDir = getCacheDirectory();
    $deleted = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                if (@unlink($file->getPathname())) {
                    $deleted++;
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Cache clear error: " . $e->getMessage());
    }
    
    return $deleted;
}

/**
 * Pobiera statystyki cache
 * 
 * @return array Statystyki cache
 */
function cache_stats(): array {
    $cacheDir = getCacheDirectory();
    $stats = [
        'enabled' => false,
        'directory' => $cacheDir,
        'files' => 0,
        'total_size' => 0,
        'oldest_file' => null,
        'newest_file' => null,
    ];
    
    $config = require __DIR__ . '/config.php';
    $stats['enabled'] = $config['app']['cache_enabled'];
    
    if (!is_dir($cacheDir)) {
        return $stats;
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $oldestTime = null;
        $newestTime = null;
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $stats['files']++;
                $stats['total_size'] += $file->getSize();
                
                $mtime = $file->getMTime();
                if ($mtime) {
                    if ($oldestTime === null || $mtime < $oldestTime) {
                        $oldestTime = $mtime;
                        $stats['oldest_file'] = date('Y-m-d H:i:s', $mtime);
                    }
                    if ($newestTime === null || $mtime > $newestTime) {
                        $newestTime = $mtime;
                        $stats['newest_file'] = date('Y-m-d H:i:s', $mtime);
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

/**
 * Sanityzuje klucz cache
 * 
 * Tworzy bezpieczną nazwę pliku z klucza cache.
 * 
 * @param string $key Oryginalny klucz
 * @return string Bezpieczny klucz
 */
function sanitizeCacheKey(string $key): string {
    $config = require __DIR__ . '/config.php';
    
    // Dodaj prefix
    $prefixedKey = $config['app']['cache_prefix'] . $key;
    
    // Hash dla długich kluczy lub zawierających niebezpieczne znaki
    if (strlen($prefixedKey) > 200 || !preg_match('/^[a-zA-Z0-9_\-:.]+$/', $prefixedKey)) {
        return $config['app']['cache_prefix'] . hash('sha256', $key);
    }
    
    return $prefixedKey;
}

/**
 * Zwraca pełną ścieżkę do pliku cache
 * 
 * @param string $safeKey Bezpieczny klucz cache
 * @return string Pełna ścieżka do pliku
 */
function getCacheFilename(string $safeKey): string {
    $cacheDir = getCacheDirectory();
    
    // Organize files in subdirectories based on first 2 chars of key
    $subdir = substr($safeKey, 0, 2);
    
    return $cacheDir . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . $safeKey . '.json';
}

/**
 * Zwraca katalog cache
 * 
 * @return string Ścieżka do katalogu cache
 */
function getCacheDirectory(): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fclass_cache';
}

/**
 * Generuje checksum dla danych
 * 
 * @param mixed $data Dane do checksum
 * @return string Checksum
 */
function generateChecksum(mixed $data): string {
    return hash('sha256', serialize($data));
}

/**
 * Sprawdza czy cache jest dostępny i funkcjonalny
 * 
 * @return bool True jeśli cache działa
 */
function cache_test(): bool {
    $testKey = 'test_' . uniqid();
    $testData = ['test' => time(), 'array' => [1, 2, 3]];
    
    // Test zapisywania
    if (!cache_set($testKey, $testData)) {
        return false;
    }
    
    // Test odczytywania
    $retrieved = cache_get($testKey, 3600); // 1 hour TTL for test
    if ($retrieved !== $testData) {
        return false;
    }
    
    // Test usuwania
    if (!cache_delete($testKey)) {
        return false;
    }
    
    // Sprawdź czy rzeczywiście usunięto
    if (cache_get($testKey, 3600) !== null) {
        return false;
    }
    
    return true;
}
?>