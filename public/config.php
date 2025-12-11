<?php

declare(strict_types=1);

/**
 * Application Configuration
 * 
 * Loads configuration from .secrets.yml and provides database connection
 * along with core utility functions.
 */

// Include security functions
require_once __DIR__ . '/security.php';

// Application Constants
const DEFAULT_DB_HOST = 'localhost';
const DEFAULT_DB_PORT = '3306';
const DEFAULT_DB_PREFIX = 'slimstore_';
const DEFAULT_RATE_LIMIT_REQUESTS = 100;
const DEFAULT_RATE_LIMIT_WINDOW = 60;
const DEFAULT_MAX_KEYS_PER_USER = 100;
const DEFAULT_MAX_VALUE_SIZE = 262144; // 256 KB
const API_KEY_BYTES = 32;
const SESSION_ID_BYTES = 32;

/**
 * Load application configuration from .secrets.yml.
 * 
 * @return array Configuration array
 * @throws Exception If secrets file is missing or unreadable
 */
function loadConfig(): array
{
    $secretsFile = __DIR__ . '/../.secrets.yml';

    if (!file_exists($secretsFile)) {
        error_log("Configuration error: Secrets file not found at $secretsFile");
        throw new Exception("Configuration error: secrets file missing");
    }

    $yamlContent = file_get_contents($secretsFile);
    if ($yamlContent === false) {
        error_log("Configuration error: Failed to read secrets file at $secretsFile");
        throw new Exception("Configuration error: unable to read secrets");
    }

    return parseSimpleYaml($yamlContent);
}

/**
 * Parse a simple YAML configuration file.
 * 
 * Supports single-level nesting with string values.
 * 
 * @param string $content YAML content to parse
 * @return array Parsed configuration
 */
function parseSimpleYaml(string $content): array
{
    $lines = explode("\n", $content);
    $result = [];
    $currentSection = null;

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Check for section header (no indentation, ends with :)
        if (!str_starts_with($line, ' ') && str_ends_with($line, ':')) {
            $currentSection = rtrim($line, ':');
            $result[$currentSection] = [];
            continue;
        }

        // Parse key-value pairs
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($currentSection !== null) {
                $result[$currentSection][$key] = $value;
            } else {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}

/**
 * Get a PDO database connection.
 * 
 * @param array $config Application configuration
 * @return PDO Database connection
 * @throws Exception If connection fails or configuration is missing
 */
function getDatabaseConnection(array $config): PDO
{
    $dbConfig = $config['database'] ?? throw new Exception("Database configuration not found");

    $dbName = $dbConfig['name'] ?? throw new Exception("Database name not configured");
    $dbUser = $dbConfig['user'] ?? throw new Exception("Database user not configured");
    $dbPassword = $dbConfig['password'] ?? throw new Exception("Database password not configured");
    $dbHost = $dbConfig['host'] ?? DEFAULT_DB_HOST;
    $dbPort = $dbConfig['port'] ?? DEFAULT_DB_PORT;

    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";

    try {
        return new PDO($dsn, $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Get the database table prefix.
 */
function getDbPrefix(array $config): string
{
    return $config['database']['prefix'] ?? DEFAULT_DB_PREFIX;
}

/**
 * Get the rate limit requests per window.
 */
function getRateLimitRequests(array $config): int
{
    return (int)($config['api']['rate_limit_requests'] ?? DEFAULT_RATE_LIMIT_REQUESTS);
}

/**
 * Get the rate limit window in seconds.
 */
function getRateLimitWindowSeconds(array $config): int
{
    return (int)($config['api']['rate_limit_window_seconds'] ?? DEFAULT_RATE_LIMIT_WINDOW);
}

/**
 * Get the maximum number of API keys per user.
 */
function getMaxKeysPerUser(array $config): int
{
    return (int)($config['api']['max_keys_per_user'] ?? DEFAULT_MAX_KEYS_PER_USER);
}

/**
 * Get the maximum value size in bytes.
 */
function getMaxValueSizeBytes(array $config): int
{
    return (int)($config['api']['max_value_size_bytes'] ?? DEFAULT_MAX_VALUE_SIZE);
}

/**
 * Get the API log mode for controlling logging verbosity.
 * 
 * Modes:
 * - 'full': Log all requests (default)
 * - 'errors': Only log errors (4xx, 5xx status codes)
 * - 'sample': Sample 10% of successful requests
 * - 'none': Disable logging completely
 */
function getApiLogMode(array $config): string
{
    return $config['api']['log_mode'] ?? 'full';
}

/**
 * Get allowed CORS origins from configuration.
 * 
 * @return string[] Array of allowed origin URLs
 */
function getAllowedOrigins(array $config): array
{
    $origins = $config['api']['allowed_origins'] ?? '';
    if ($origins === '') {
        return [];
    }
    return array_map('trim', explode(',', $origins));
}

/**
 * Get the configured domain name.
 */
function getDomainName(array $config): string
{
    return $config['domain']['name'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
}

/**
 * Get the base URL (with scheme).
 */
function getBaseUrl(array $config): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    return $scheme . '://' . getDomainName($config);
}

/**
 * Check if running in production environment.
 */
function isProduction(array $config): bool
{
    $domain = getDomainName($config);
    return $domain !== 'localhost'
        && !str_contains($domain, '127.0.0.1')
        && !str_contains($domain, '.local');
}

/**
 * Get the client IP address (handles proxies and load balancers).
 */
function getClientIpAddress(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated IPs (X-Forwarded-For can have multiple)
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Send a JSON response and exit.
 */
function sendJsonResponse(int $statusCode, array $data): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Generate a cryptographically secure API key (64 hex characters).
 */
function generateApiKey(): string
{
    return bin2hex(random_bytes(API_KEY_BYTES));
}

/**
 * Generate a cryptographically secure session ID (64 hex characters).
 */
function generateSessionId(): string
{
    return bin2hex(random_bytes(SESSION_ID_BYTES));
}

/**
 * Generate a UUID v4 string (lowercase).
 */
function generateUuidV4(): string
{
    $data = random_bytes(16);

    // Set version to 0100 (UUID v4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return strtolower(sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(substr($data, 0, 4)),
        bin2hex(substr($data, 4, 2)),
        bin2hex(substr($data, 6, 2)),
        bin2hex(substr($data, 8, 2)),
        bin2hex(substr($data, 10, 6))
    ));
}

/**
 * Log an API request for analytics.
 * 
 * Respects the configured log_mode to reduce database writes for high-volume APIs:
 * - 'full': Log all requests (default)
 * - 'errors': Only log errors (4xx, 5xx status codes)
 * - 'sample': Sample 10% of successful requests
 * - 'none': Disable logging completely
 */
function logApiRequest(PDO $pdo, array $config, int $apiKeyId, string $endpoint, string $method, int $statusCode): void
{
    try {
        $logMode = getApiLogMode($config);
        
        // Check if we should skip logging based on mode
        if ($logMode === 'none') {
            return;
        }
        
        $isError = $statusCode >= 400;
        
        if ($logMode === 'errors' && !$isError) {
            return;
        }
        
        if ($logMode === 'sample' && !$isError) {
            // Sample only 10% of successful requests
            if (random_int(1, 10) !== 1) {
                return;
            }
        }
        
        $prefix = getDbPrefix($config);
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}api_logs (api_key_id, endpoint, method, status_code) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$apiKeyId, $endpoint, $method, $statusCode]);
        
        // Update pre-computed stats tables (best effort, don't fail if tables don't exist)
        try {
            // Update daily stats
            $isSuccess = $statusCode >= 200 && $statusCode < 300;
            $isError = $statusCode >= 400;
            
            $stmt = $pdo->prepare("
                INSERT INTO {$prefix}api_logs_stats (api_key_id, stat_date, total_requests, success_requests, error_requests)
                VALUES (?, CURDATE(), 1, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    total_requests = total_requests + 1,
                    success_requests = success_requests + VALUES(success_requests),
                    error_requests = error_requests + VALUES(error_requests)
            ");
            $stmt->execute([$apiKeyId, $isSuccess ? 1 : 0, $isError ? 1 : 0]);
            
            // Update endpoint stats
            $stmt = $pdo->prepare("
                INSERT INTO {$prefix}api_logs_endpoint_stats (api_key_id, endpoint, request_count)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    request_count = request_count + 1,
                    last_request = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$apiKeyId, $endpoint]);
        } catch (Exception $e) {
            // Stats tables might not exist yet, silently ignore
        }
    } catch (Exception $e) {
        // Silently fail - don't break API functionality if logging fails
        error_log("Failed to log API request: " . $e->getMessage());
    }
}

/**
 * Get avatar URL for a user with fallback chain.
 * 
 * Priority: Google photo -> Gravatar (mystery person fallback)
 * 
 * @param string|null $photoUrl Google profile photo URL
 * @param string $email User email address
 * @param int $size Avatar size in pixels
 * @return string Avatar URL
 */
function getAvatarUrl(?string $photoUrl, string $email, int $size = 96): string
{
    // Try Google photo first
    if ($photoUrl !== null && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
        if (str_contains($photoUrl, 'googleusercontent.com')) {
            return $photoUrl . '?sz=' . $size;
        }
        return $photoUrl;
    }

    // Fallback to Gravatar with mystery person default
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
}

/**
 * Get initials from email for fallback avatar display.
 * 
 * @param string $email User email address
 * @return string User initials (max 2 characters, uppercase)
 */
function getUserInitials(string $email): string
{
    $parts = explode('@', $email);
    $username = $parts[0];

    // Try to extract initials from username separators
    if (str_contains($username, '.')) {
        $nameParts = explode('.', $username);
        return strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1] ?? '', 0, 1));
    }

    if (str_contains($username, '_')) {
        $nameParts = explode('_', $username);
        return strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1] ?? '', 0, 1));
    }

    return strtoupper(substr($username, 0, 2));
}

