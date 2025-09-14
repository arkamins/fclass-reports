<?php
/**
 * Zarządzanie kodowaniem i sanityzacją danych
 * 
 * Bezpieczna konwersja kodowania znaków oraz sanityzacja danych wejściowych/wyjściowych.
 * Obsługuje różne źródła kodowania oraz walidację bezpieczeństwa.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

/**
 * Konwertuje dane do UTF-8
 * 
 * Rekurencyjnie konwertuje dane (stringi, tablice) do UTF-8 z obsługą
 * różnych kodowań źródłowych. Obsługuje także nested arrays i objects.
 * 
 * @param mixed $value Dane do konwersji
 * @param string|null $sourceCharset Kodowanie źródłowe (null = auto z config)
 * @return mixed Dane skonwertowane do UTF-8
 */
function to_utf8(mixed $value, ?string $sourceCharset = null): mixed {
    $config = require __DIR__ . '/config.php';
    
    // Określ kodowanie źródłowe
    if ($sourceCharset === null) {
        $sourceCharset = $config['app']['source_charset'] ?? 'utf8';
    }
    
    // Obsługa różnych typów danych
    return convertToUtf8Recursive($value, $sourceCharset);
}

/**
 * Rekurencyjna konwersja do UTF-8
 * 
 * Wewnętrzna funkcja obsługująca głębokie struktury danych.
 * 
 * @param mixed $value Wartość do konwersji
 * @param string $sourceCharset Kodowanie źródłowe
 * @return mixed Skonwertowana wartość
 */
function convertToUtf8Recursive(mixed $value, string $sourceCharset): mixed {
    // Obsługa null
    if ($value === null) {
        return '';
    }
    
    // Obsługa tablic
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            // Konwertuj zarówno klucze jak i wartości
            $convertedKey = convertToUtf8Recursive($key, $sourceCharset);
            $convertedValue = convertToUtf8Recursive($item, $sourceCharset);
            $result[$convertedKey] = $convertedValue;
        }
        return $result;
    }
    
    // Obsługa obiektów
    if (is_object($value)) {
        // Konwertuj properties obiektów
        $reflection = new ReflectionObject($value);
        $properties = $reflection->getProperties();
        
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $originalValue = $property->getValue($value);
            $convertedValue = convertToUtf8Recursive($originalValue, $sourceCharset);
            $property->setValue($value, $convertedValue);
        }
        
        return $value;
    }
    
    // Obsługa stringów
    if (is_string($value)) {
        return convertStringToUtf8($value, $sourceCharset);
    }
    
    // Inne typy - zwróć bez zmian
    return $value;
}

/**
 * Konwertuje string do UTF-8
 * 
 * @param string $string String do konwersji
 * @param string $sourceCharset Kodowanie źródłowe
 * @return string Skonwertowany string
 */
function convertStringToUtf8(string $string, string $sourceCharset): string {
    // Jeśli już UTF-8 i poprawne
    if ($sourceCharset === 'utf8' || $sourceCharset === 'utf-8') {
        // Sprawdź czy string jest już poprawnym UTF-8
        if (mb_check_encoding($string, 'UTF-8')) {
            return $string;
        }
    }
    
    // Mapowanie kodowań
    $charsetMap = [
        'latin2' => 'ISO-8859-2',
        'latin1' => 'ISO-8859-1',
        'cp1250' => 'Windows-1250',
        'cp1252' => 'Windows-1252',
        'utf8' => 'UTF-8',
        'utf-8' => 'UTF-8',
    ];
    
    $targetCharset = $charsetMap[$sourceCharset] ?? $sourceCharset;
    
    // Próba konwersji z użyciem mb_convert_encoding
    try {
        $converted = @mb_convert_encoding($string, 'UTF-8', $targetCharset);
        
        // Sprawdź czy konwersja się udała
        if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    } catch (Exception $e) {
        error_log("Encoding conversion error: " . $e->getMessage());
    }
    
    // Fallback: usuń niepoprawne znaki
    return mb_convert_encoding($string, 'UTF-8', 'UTF-8');
}

/**
 * Bezpieczne escapowanie HTML
 * 
 * Konwertuje do UTF-8 i escapuje HTML entities dla bezpiecznego wyświetlania.
 * 
 * @param mixed $value Wartość do escapowania
 * @param int $flags Flagi dla htmlspecialchars
 * @param string|null $encoding Kodowanie (domyślnie UTF-8)
 * @return string Bezpieczny string HTML
 */
function e(mixed $value, int $flags = ENT_QUOTES, ?string $encoding = 'UTF-8'): string {
    // Konwertuj do UTF-8
    $utf8Value = to_utf8($value);
    
    // Jeśli nie string, konwertuj do string
    if (!is_string($utf8Value)) {
        $utf8Value = (string)$utf8Value;
    }
    
    // Escapuj HTML
    return htmlspecialchars($utf8Value, $flags, $encoding);
}

/**
 * Sanityzuje dane wejściowe
 * 
 * Czyści dane wejściowe z potencjalnie niebezpiecznych znaków.
 * 
 * @param mixed $input Dane wejściowe
 * @param array $options Opcje sanityzacji
 * @return mixed Zsanityzowane dane
 */
function sanitize_input(mixed $input, array $options = []): mixed {
    $config = require __DIR__ . '/config.php';
    
    // Sprawdź czy sanityzacja jest włączona
    if (!$config['app']['sanitize_inputs']) {
        return $input;
    }
    
    // Domyślne opcje
    $defaultOptions = [
        'trim' => true,
        'strip_tags' => false,
        'remove_null_bytes' => true,
        'normalize_newlines' => true,
        'max_length' => null,
    ];
    
    $options = array_merge($defaultOptions, $options);
    
    return sanitizeRecursive($input, $options);
}

/**
 * Rekurencyjna sanityzacja
 * 
 * @param mixed $value Wartość do sanityzacji
 * @param array $options Opcje sanityzacji
 * @return mixed Zsanityzowana wartość
 */
function sanitizeRecursive(mixed $value, array $options): mixed {
    if (is_array($value)) {
        return array_map(
            fn($item) => sanitizeRecursive($item, $options), 
            $value
        );
    }
    
    if (!is_string($value)) {
        return $value;
    }
    
    // Usuń null bytes (bezpieczeństwo)
    if ($options['remove_null_bytes']) {
        $value = str_replace("\0", '', $value);
    }
    
    // Trim whitespace
    if ($options['trim']) {
        $value = trim($value);
    }
    
    // Strip HTML tags
    if ($options['strip_tags']) {
        $value = strip_tags($value);
    }
    
    // Normalize newlines
    if ($options['normalize_newlines']) {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
    }
    
    // Limit length
    if ($options['max_length'] && strlen($value) > $options['max_length']) {
        $value = mb_substr($value, 0, $options['max_length'], 'UTF-8');
    }
    
    return $value;
}

/**
 * Waliduje kodowanie stringu
 * 
 * @param string $string String do sprawdzenia
 * @param string $encoding Oczekiwane kodowanie
 * @return bool True jeśli kodowanie jest poprawne
 */
function validate_encoding(string $string, string $encoding = 'UTF-8'): bool {
    return mb_check_encoding($string, $encoding);
}

/**
 * Wykrywa kodowanie stringu
 * 
 * @param string $string String do analizy
 * @param array|null $encodings Lista kodowań do sprawdzenia
 * @return string|false Wykryte kodowanie lub false
 */
function detect_encoding(string $string, ?array $encodings = null): string|false {
    if ($encodings === null) {
        $encodings = ['UTF-8', 'ISO-8859-2', 'ISO-8859-1', 'Windows-1250', 'Windows-1252'];
    }
    
    return mb_detect_encoding($string, $encodings, true);
}

/**
 * Konwertuje dane do formatu CSV
 * 
 * Przygotowuje dane do eksportu CSV z odpowiednim kodowaniem.
 * 
 * @param mixed $value Wartość do konwersji
 * @param bool $addBom Czy dodać UTF-8 BOM
 * @return string String gotowy do CSV
 */
function to_csv(mixed $value, bool $addBom = true): string {
    $config = require __DIR__ . '/config.php';
    
    // Konwertuj do UTF-8
    $utf8Value = to_utf8($value);
    
    // Jeśli nie string, konwertuj
    if (!is_string($utf8Value)) {
        $utf8Value = (string)$utf8Value;
    }
    
    // Dodaj BOM jeśli wymagany (dla Excel)
    $result = '';
    if ($addBom && $config['export']['csv']['bom']) {
        $result = "\xEF\xBB\xBF";
    }
    
    return $result . $utf8Value;
}

/**
 * Formatuje liczbę zgodnie z lokalizacją
 * 
 * @param float|int $number Liczba do formatowania
 * @param int $decimals Liczba miejsc po przecinku
 * @param bool $useLocale Czy używać ustawień lokalnych
 * @return string Sformatowana liczba
 */
function format_number(float|int $number, int $decimals = 0, bool $useLocale = true): string {
    $config = require __DIR__ . '/config.php';
    
    if ($useLocale) {
        $decimalSep = $config['ui']['decimal_separator'];
        $thousandSep = $config['ui']['thousand_separator'];
    } else {
        $decimalSep = '.';
        $thousandSep = '';
    }
    
    return number_format($number, $decimals, $decimalSep, $thousandSep);
}

/**
 * Formatuje datę zgodnie z lokalizacją
 * 
 * @param int|string $timestamp Timestamp lub string daty
 * @param string|null $format Format daty (null = domyślny z config)
 * @return string Sformatowana data
 */
function format_date(int|string $timestamp, ?string $format = null): string {
    $config = require __DIR__ . '/config.php';
    
    if ($format === null) {
        $format = $config['ui']['date_format'];
    }
    
    // Konwertuj string na timestamp jeśli potrzeba
    if (is_string($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    
    if ($timestamp === false) {
        return '';
    }
    
    return date($format, $timestamp);
}

/**
 * Czyści dane przed zapisem do bazy
 * 
 * @param mixed $value Wartość do oczyszczenia
 * @return mixed Oczyszczona wartość
 */
function clean_for_db(mixed $value): mixed {
    // Konwertuj do UTF-8
    $value = to_utf8($value);
    
    // Sanityzuj
    $value = sanitize_input($value, [
        'trim' => true,
        'remove_null_bytes' => true,
        'normalize_newlines' => true,
    ]);
    
    return $value;
}
?>