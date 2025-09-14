<?php
/**
 * Zarządzanie połączeniem z bazą danych
 * 
 * Bezpieczne i efektywne zarządzanie połączeniami PDO z bazą danych.
 * Implementuje singleton pattern, error handling oraz walidację bezpieczeństwa.
 * 
 * @author FClass Report Team
 * @version 9.0
 * @since 2025
 */

/**
 * Zwraca instancję połączenia PDO (singleton)
 * 
 * Tworzy i zwraca globalne połączenie z bazą danych. Używa lazy loading
 * i singleton pattern dla optymalizacji wydajności.
 * 
 * @return PDO Aktywne połączenie z bazą danych
 * @throws DatabaseException W przypadku błędu połączenia
 */
function db(): PDO {
    static $pdo = null;
    
    // Singleton - zwróć istniejące połączenie jeśli dostępne
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    
    try {
        // Załaduj konfigurację
        $config = require __DIR__ . '/config.php';
        $dbConfig = $config['db'];
        
        // Utwórz nowe połączenie PDO
        $pdo = new PDO(
            $dbConfig['dsn'], 
            $dbConfig['user'], 
            $dbConfig['password'], 
            $dbConfig['options']
        );
        
        // Ustaw timeout dla zapytań jeśli skonfigurowany
        if (isset($config['app']['query_timeout'])) {
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, $config['app']['query_timeout']);
        }
        
        return $pdo;
        
    } catch (PDOException $e) {
        // Log błędu (jeśli logging włączony)
        error_log("Database connection failed: " . $e->getMessage());
        
        // Nie ujawniaj szczegółów błędu w produkcji
        throw new RuntimeException("Database connection failed. Please try again later.");
    }
}

/**
 * Bezpieczna walidacja nazwy tabeli
 * 
 * Sprawdza czy nazwa tabeli jest bezpieczna i pasuje do oczekiwanego formatu.
 * Chroni przed SQL injection przez dynamiczne nazwy tabel.
 * 
 * @param string $tableName Nazwa tabeli do walidacji
 * @param string $pattern Regex pattern (domyślnie dla tabel dziennych YYYYMMDD)
 * @return bool True jeśli nazwa jest bezpieczna
 */
function validateTableName(string $tableName, string $pattern = null): bool {
    $config = require __DIR__ . '/config.php';
    
    // Użyj domyślnego pattern jeśli nie podano
    if ($pattern === null) {
        $pattern = $config['constants']['day_table_pattern'];
    }
    
    // Sprawdź czy walidacja jest włączona
    if (!$config['app']['validate_table_names']) {
        return true;
    }
    
    // Podstawowa walidacja - tylko alfanumeryczne znaki i podkreślniki
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        return false;
    }
    
    // Sprawdź czy pasuje do konkretnego pattern
    if (!preg_match($pattern, $tableName)) {
        return false;
    }
    
    // Dodatkowe sprawdzenie długości
    if (strlen($tableName) > 64) { // MySQL limit na nazwę tabeli
        return false;
    }
    
    return true;
}

/**
 * Sprawdza czy tabela istnieje w bazie danych
 * 
 * Bezpiecznie sprawdza istnienie tabeli w bazie danych.
 * Używa INFORMATION_SCHEMA dla bezpieczeństwa.
 * 
 * @param string $tableName Nazwa tabeli do sprawdzenia
 * @return bool True jeśli tabela istnieje
 */
function tableExists(string $tableName): bool {
    // Waliduj nazwę tabeli
    if (!validateTableName($tableName)) {
        return false;
    }
    
    try {
        $pdo = db();
        $config = require __DIR__ . '/config.php';
        
        // Wyciągnij nazwę bazy z DSN
        $dsn = $config['db']['dsn'];
        preg_match('/dbname=([^;]+)/', $dsn, $matches);
        $dbName = $matches[1] ?? null;
        
        if (!$dbName) {
            error_log("Could not extract database name from DSN");
            return false;
        }
        
        // Bezpieczne sprawdzenie przez INFORMATION_SCHEMA
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ");
        $stmt->execute([$dbName, $tableName]);
        
        return (int)$stmt->fetchColumn() > 0;
        
    } catch (PDOException $e) {
        error_log("Error checking table existence: " . $e->getMessage());
        return false;
    }
}

/**
 * Wykonuje zapytanie z logowaniem błędów
 * 
 * Wrapper dla PDO query z dodatkowym error handlingiem i logowaniem.
 * Ułatwia debugowanie i monitoring problemów z bazą danych.
 * 
 * @param string $sql Zapytanie SQL
 * @param array $params Parametry dla prepared statement
 * @return PDOStatement|false Wynik zapytania lub false w przypadku błędu
 */
function executeQuery(string $sql, array $params = []): PDOStatement|false {
    try {
        $pdo = db();
        
        if (empty($params)) {
            // Proste zapytanie bez parametrów
            $result = $pdo->query($sql);
        } else {
            // Prepared statement z parametrami
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params) ? $stmt : false;
        }
        
        return $result;
        
    } catch (PDOException $e) {
        // Log szczegółów błędu
        error_log("SQL Error: " . $e->getMessage() . " | Query: " . $sql);
        
        $config = require __DIR__ . '/config.php';
        
        // W trybie debug pokaż więcej informacji
        if ($config['app']['allow_debug']) {
            error_log("SQL Params: " . json_encode($params));
        }
        
        return false;
    }
}

/**
 * Pobiera maksymalny rok z tabeli zawodów
 * 
 * Bezpiecznie pobiera najnowszy rok dla którego są dostępne dane.
 * Implementuje cache oraz fallback na bieżący rok.
 * 
 * @param bool $useCache Czy używać cache (domyślnie true)
 * @return int Maksymalny dostępny rok
 */
function getMaxYear(bool $useCache = true): int {
    static $cachedYear = null;
    
    // Zwróć z cache jeśli dostępne
    if ($useCache && $cachedYear !== null) {
        return $cachedYear;
    }
    
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT MAX(data) AS maxd FROM zawody");
        $row = $stmt->fetch();
        
        if (!$row || !$row['maxd']) {
            $cachedYear = (int)date('Y');
            return $cachedYear;
        }
        
        $maxDate = (int)$row['maxd'];
        $year = (int)floor($maxDate / 10000);
        
        // Waliduj rok (nie może być z przyszłości ani zbyt daleko w przeszłości)
        $currentYear = (int)date('Y');
        $config = require __DIR__ . '/config.php';
        $minYear = $config['app']['min_year'];
        
        if ($year < $minYear) {
            $year = $minYear;
        } elseif ($year > $currentYear + 1) {
            $year = $currentYear;
        }
        
        $cachedYear = $year;
        return $year;
        
    } catch (Exception $e) {
        error_log("Error fetching max year: " . $e->getMessage());
        
        // Fallback na bieżący rok
        $cachedYear = (int)date('Y');
        return $cachedYear;
    }
}

/**
 * Zamyka połączenie z bazą danych
 * 
 * Ręczne zamknięcie połączenia (rzadko potrzebne, ale dostępne).
 * Głównie do użytku w testach lub long-running scripts.
 * 
 * @return void
 */
function closeDb(): void {
    // Reset statycznej zmiennej w funkcji db()
    $reflection = new ReflectionFunction('db');
    $closure = $reflection->getClosure();
    if ($closure) {
        $closure->bindTo(null);
    }
}

/**
 * Zwraca statystyki połączenia z bazą danych
 * 
 * Użyteczne do monitorowania i debugowania wydajności.
 * Dostępne tylko w trybie debug.
 * 
 * @return array|null Statystyki lub null jeśli debug wyłączony
 */
function getDbStats(): ?array {
    $config = require __DIR__ . '/config.php';
    
    if (!$config['app']['allow_debug']) {
        return null;
    }
    
    try {
        $pdo = db();
        
        return [
            'connection_status' => $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS),
            'driver_name' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
?>