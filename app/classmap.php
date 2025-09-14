<?php
/**
 * Mapowanie klas zawodów strzeleckich
 * 
 * Centralne zarządzanie mapowaniem identyfikatorów klas na nazwy wyświetlane.
 * Obsługuje różne formaty wyjściowe, walidację oraz rozszerzalność.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

/**
 * Zwraca nazwę klasy na podstawie identyfikatora
 * 
 * Główna funkcja mapująca ID klasy na nazwę wyświetlaną. Obsługuje cache,
 * walidację oraz fallback dla nieznanych klas.
 * 
 * @param string|int $classKey Identyfikator klasy
 * @param string $format Format zwracanej nazwy ('display', 'short', 'code')
 * @param bool $strict Czy rzucać wyjątek dla nieznanych klas
 * @return string Nazwa klasy
 * @throws InvalidArgumentException Gdy klasa nie istnieje i $strict = true
 */
function class_map_name(string|int $classKey, string $format = 'display', bool $strict = false): string {
    static $cache = [];
    
    // Normalizuj klucz do stringa
    $key = (string)$classKey;
    
    // Sprawdź cache
    $cacheKey = "{$key}_{$format}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    // Pobierz mapowanie z konfiguracji
    $config = require __DIR__ . '/config.php';
    $classMap = $config['classes'] ?? [];
    
    // Sprawdź czy klasa istnieje
    if (!isset($classMap[$key])) {
        if ($strict) {
            throw new InvalidArgumentException("Unknown class ID: {$key}");
        }
        
        // Fallback - zwróć oryginalny klucz
        $result = $key;
        $cache[$cacheKey] = $result;
        return $result;
    }
    
    $className = $classMap[$key];
    
    // Formatuj nazwę zgodnie z wymaganym formatem
    $result = formatClassName($className, $format);
    
    // Zapisz w cache
    $cache[$cacheKey] = $result;
    
    return $result;
}

/**
 * Formatuje nazwę klasy zgodnie z wybranym formatem
 * 
 * @param string $className Oryginalna nazwa klasy
 * @param string $format Format ('display', 'short', 'code', 'slug')
 * @return string Sformatowana nazwa
 */
function formatClassName(string $className, string $format): string {
    switch ($format) {
        case 'display':
            // Pełna nazwa do wyświetlania (domyślny)
            return $className;
            
        case 'short':
            // Skrócona nazwa (pierwsze 8 znaków lub do pierwszego spacji)
            $shortName = explode(' ', $className)[0];
            return mb_substr($shortName, 0, 8, 'UTF-8');
            
        case 'code':
            // Kod klasy (uppercase, bez spacji)
            return strtoupper(str_replace([' ', '-'], '_', $className));
            
        case 'slug':
            // URL-friendly slug
            return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $className));
            
        default:
            return $className;
    }
}

/**
 * Zwraca wszystkie dostępne klasy
 * 
 * @param string $format Format nazw klas
 * @param bool $includeIds Czy dołączyć ID klas do wyniku
 * @return array Tablica klas [id => nazwa] lub [nazwa, nazwa, ...]
 */
function get_all_classes(string $format = 'display', bool $includeIds = true): array {
    $config = require __DIR__ . '/config.php';
    $classMap = $config['classes'] ?? [];
    
    if (!$includeIds) {
        // Zwróć tylko nazwy
        return array_map(
            fn($className) => formatClassName($className, $format), 
            array_values($classMap)
        );
    }
    
    // Zwróć mapowanie ID => nazwa
    $result = [];
    foreach ($classMap as $id => $className) {
        $result[$id] = formatClassName($className, $format);
    }
    
    return $result;
}

/**
 * Wyszukuje klasy po nazwie
 * 
 * @param string $searchTerm Szukana fraza
 * @param bool $caseSensitive Czy wyszukiwanie ma być case-sensitive
 * @return array Znalezione klasy [id => nazwa]
 */
function search_classes(string $searchTerm, bool $caseSensitive = false): array {
    $allClasses = get_all_classes('display', true);
    $result = [];
    
    $searchTerm = $caseSensitive ? $searchTerm : mb_strtolower($searchTerm, 'UTF-8');
    
    foreach ($allClasses as $id => $name) {
        $compareName = $caseSensitive ? $name : mb_strtolower($name, 'UTF-8');
        
        if (mb_strpos($compareName, $searchTerm, 0, 'UTF-8') !== false) {
            $result[$id] = $name;
        }
    }
    
    return $result;
}

/**
 * Waliduje ID klasy
 * 
 * @param string|int $classId ID klasy do walidacji
 * @return bool True jeśli ID jest poprawne
 */
function validate_class_id(string|int $classId): bool {
    $config = require __DIR__ . '/config.php';
    $classMap = $config['classes'] ?? [];
    
    return isset($classMap[(string)$classId]);
}

/**
 * Zwraca ID klasy na podstawie nazwy
 * 
 * Odwrotność funkcji class_map_name - znajduje ID po nazwie.
 * 
 * @param string $className Nazwa klasy
 * @param bool $strict Czy wyszukiwanie ma być dokładne
 * @return string|null ID klasy lub null jeśli nie znaleziono
 */
function get_class_id_by_name(string $className, bool $strict = true): ?string {
    $config = require __DIR__ . '/config.php';
    $classMap = $config['classes'] ?? [];
    
    // Dokładne dopasowanie
    $exactMatch = array_search($className, $classMap, true);
    if ($exactMatch !== false) {
        return (string)$exactMatch;
    }
    
    if ($strict) {
        return null;
    }
    
    // Fuzzy matching (case-insensitive)
    $searchName = mb_strtolower($className, 'UTF-8');
    
    foreach ($classMap as $id => $name) {
        if (mb_strtolower($name, 'UTF-8') === $searchName) {
            return (string)$id;
        }
    }
    
    return null;
}

/**
 * Sortuje klasy według określonego porządku
 * 
 * @param array $classIds Lista ID klas do posortowania
 * @param string $method Metoda sortowania ('numeric', 'alphabetic', 'custom')
 * @param array $customOrder Niestandardowy porządek (dla method='custom')
 * @return array Posortowane ID klas
 */
function sort_classes(array $classIds, string $method = 'numeric', array $customOrder = []): array {
    switch ($method) {
        case 'numeric':
            // Sortuj numerycznie (1, 2, 3...)
            usort($classIds, function($a, $b) {
                $ia = (int)$a;
                $ib = (int)$b;
                
                // Jeśli oba są liczbami, sortuj numerycznie
                if ((string)$ia === $a && (string)$ib === $b) {
                    return $ia <=> $ib;
                }
                
                // W przeciwnym razie sortuj alfabetycznie
                return strcmp($a, $b);
            });
            break;
            
        case 'alphabetic':
            // Sortuj alfabetycznie po nazwach klas
            usort($classIds, function($a, $b) {
                $nameA = class_map_name($a);
                $nameB = class_map_name($b);
                return strcoll($nameA, $nameB);
            });
            break;
            
        case 'custom':
            // Sortuj według podanego porządku
            if (!empty($customOrder)) {
                $orderMap = array_flip($customOrder);
                usort($classIds, function($a, $b) use ($orderMap) {
                    $posA = $orderMap[$a] ?? 999;
                    $posB = $orderMap[$b] ?? 999;
                    return $posA <=> $posB;
                });
            }
            break;
    }
    
    return $classIds;
}

/**
 * Generuje mapę CSS klas dla różnych klas zawodów
 * 
 * Przydatne do stylowania interfejsu użytkownika.
 * 
 * @return array Mapa [class_id => css_class]
 */
function get_class_css_map(): array {
    $allClasses = get_all_classes('display', true);
    $cssMap = [];
    
    foreach ($allClasses as $id => $name) {
        // Generuj CSS class na podstawie nazwy
        $cssClass = 'class-' . formatClassName($name, 'slug');
        $cssMap[$id] = $cssClass;
    }
    
    return $cssMap;
}

/**
 * Eksportuje mapowanie klas do różnych formatów
 * 
 * @param string $format Format eksportu ('json', 'php', 'csv')
 * @return string Wyeksportowane dane
 */
function export_class_mapping(string $format = 'json'): string {
    $allClasses = get_all_classes('display', true);
    
    switch ($format) {
        case 'json':
            return json_encode($allClasses, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
        case 'php':
            $export = "<?php\n\nreturn " . var_export($allClasses, true) . ";\n";
            return $export;
            
        case 'csv':
            $csv = "ID,Name\n";
            foreach ($allClasses as $id => $name) {
                $csv .= '"' . addslashes($id) . '","' . addslashes($name) . "\"\n";
            }
            return $csv;
            
        default:
            throw new InvalidArgumentException("Unsupported export format: {$format}");
    }
}

/**
 * Sprawdza integralność mapowania klas
 * 
 * Waliduje czy mapowanie jest spójne i kompletne.
 * 
 * @return array Raport z wynikami sprawdzenia
 */
function validate_class_mapping(): array {
    $config = require __DIR__ . '/config.php';
    $classMap = $config['classes'] ?? [];
    
    $report = [
        'valid' => true,
        'total_classes' => count($classMap),
        'issues' => [],
    ];
    
    foreach ($classMap as $id => $name) {
        // Sprawdź ID
        if (!preg_match('/^[1-9]\d*$/', (string)$id)) {
            $report['issues'][] = "Invalid class ID format: {$id}";
            $report['valid'] = false;
        }
        
        // Sprawdź nazwę
        if (empty($name) || !is_string($name)) {
            $report['issues'][] = "Invalid class name for ID {$id}";
            $report['valid'] = false;
        }
        
        // Sprawdź duplikaty nazw
        $duplicates = array_keys($classMap, $name);
        if (count($duplicates) > 1) {
            $report['issues'][] = "Duplicate class name '{$name}' for IDs: " . implode(', ', $duplicates);
            $report['valid'] = false;
        }
    }
    
    return $report;
}

// Alias funkcji dla backward compatibility
if (!function_exists('class_map_name_legacy')) {
    /**
     * @deprecated Użyj class_map_name() zamiast tego
     */
    function class_map_name_legacy($classKey) {
        return class_map_name($classKey);
    }
}
?>