<?php

declare(strict_types=1);

/**
 * Shared API Helper Functions
 * 
 * Common utilities for rate limiting, authentication, and request handling
 * used by both the Key/Value Store API and Event Data API.
 */

// API Constants
const API_MAX_BATCH_SIZE = 1000;
const API_MAX_QUERY_LIMIT = 10000;
const API_MAX_PAYLOAD_SIZE = 1048576; // 1MB
const API_KEY_LENGTH = 64;
const UUID_LENGTH = 36;

// Logging configuration
const API_LOG_MODE_FULL = 'full';        // Log all requests (default)
const API_LOG_MODE_ERRORS = 'errors';    // Only log errors (4xx, 5xx)
const API_LOG_MODE_SAMPLE = 'sample';    // Sample 10% of successful requests
const API_LOG_MODE_NONE = 'none';        // Disable logging completely

/**
 * Configure CORS headers for API requests.
 * 
 * @param array $config Application configuration
 */
function configureCors(array $config): void
{
    $allowedOrigins = getAllowedOrigins($config);
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (!empty($allowedOrigins) && in_array($requestOrigin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
    } elseif (empty($allowedOrigins)) {
        header('Access-Control-Allow-Origin: https://' . getDomainName($config));
    }

    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Allow-Credentials: true');
}

/**
 * Handle preflight (OPTIONS) requests for CORS.
 */
function handlePreflightRequest(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Extract and validate API key from request headers.
 * 
 * On failure, terminates with JSON error response (does not return).
 * 
 * @return string The validated API key
 */
function extractApiKey(): string
{
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

    if ($apiKey === null) {
        logSecurityEvent('missing_api_key', ['endpoint' => $_SERVER['REQUEST_URI']]);
        sendJsonResponse(401, [
            'status' => 'error',
            'message' => 'Missing X-API-Key header'
        ]);
    }

    $apiKey = sanitizeInput($apiKey, API_KEY_LENGTH);

    if (!validateApiKeyFormat($apiKey)) {
        logSecurityEvent('invalid_api_key_format', ['key' => substr($apiKey, 0, 8) . '...']);
        sendJsonResponse(401, [
            'status' => 'error',
            'message' => 'Invalid API key format'
        ]);
    }

    return $apiKey;
}

/**
 * Authenticate API key and return the API key ID.
 * 
 * On failure, terminates with JSON error response (does not return).
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $apiKey The API key to validate
 * @return int The API key ID
 */
function authenticateApiKey(PDO $pdo, array $config, string $apiKey): int
{
    $apiKeyId = validateApiKeyInDatabase($pdo, $config, $apiKey);

    if ($apiKeyId === null) {
        logSecurityEvent('invalid_api_key', ['key' => substr($apiKey, 0, 8) . '...']);
        sendJsonResponse(401, [
            'status' => 'error',
            'message' => 'Invalid API key'
        ]);
    }

    return $apiKeyId;
}

/**
 * Validate API key exists in database.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $apiKey The API key to check
 * @return int|null API key ID if valid, null otherwise
 */
function validateApiKeyInDatabase(PDO $pdo, array $config, string $apiKey): ?int
{
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("SELECT id FROM {$prefix}api_keys WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $result = $stmt->fetch();

    return $result ? (int)$result['id'] : null;
}

/**
 * Update the last_used_at timestamp for an API key.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param int $apiKeyId The API key ID to update
 */
function updateApiKeyLastUsed(PDO $pdo, array $config, int $apiKeyId): void
{
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("UPDATE {$prefix}api_keys SET last_used_at = NOW() WHERE id = ?");
    $stmt->execute([$apiKeyId]);
}

/**
 * Check and enforce rate limits for API requests.
 * Uses API key hash as identifier for rate limiting.
 * 
 * On rate limit exceeded, terminates with 429 response (does not return).
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $apiKey The API key to rate limit
 */
function enforceRateLimit(PDO $pdo, array $config, string $apiKey): void
{
    $now = new DateTime();
    $prefix = getDbPrefix($config);
    $maxRequests = getRateLimitRequests($config);
    $windowSeconds = getRateLimitWindowSeconds($config);

    // Use API key hash as identifier for rate limiting
    $identifier = 'key:' . substr(hash('sha256', $apiKey), 0, 32);
    $windowStart = (clone $now)->modify("-{$windowSeconds} seconds");

    // Atomic upsert to avoid race conditions
    $stmt = $pdo->prepare("
        INSERT INTO {$prefix}rate_limits (ip_address, request_count, window_start) 
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE 
            request_count = IF(window_start > ?, request_count + 1, 1),
            window_start = IF(window_start > ?, window_start, VALUES(window_start))
    ");
    $stmt->execute([
        $identifier,
        $now->format('Y-m-d H:i:s'),
        $windowStart->format('Y-m-d H:i:s'),
        $windowStart->format('Y-m-d H:i:s')
    ]);

    // Check current count
    $stmt = $pdo->prepare("
        SELECT request_count, window_start 
        FROM {$prefix}rate_limits 
        WHERE ip_address = ?
    ");
    $stmt->execute([$identifier]);
    $record = $stmt->fetch();

    if ($record && $record['request_count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        sendJsonResponse(429, [
            'status' => 'error',
            'message' => "Rate limit exceeded. Maximum {$maxRequests} requests per {$windowSeconds} seconds.",
            'retry_after' => $windowSeconds
        ]);
    }

    // Probabilistic cleanup of old records (1% of requests)
    if (random_int(1, 100) === 1) {
        $cleanupTime = (clone $now)->modify("-" . ($windowSeconds * 2) . " seconds");
        $stmt = $pdo->prepare("DELETE FROM {$prefix}rate_limits WHERE window_start < ?");
        $stmt->execute([$cleanupTime->format('Y-m-d H:i:s')]);
    }
}

/**
 * Initialize API request with standard security checks.
 * 
 * @param array $config Application configuration
 * @param PDO $pdo Database connection
 * @return array{apiKey: string, apiKeyId: int} Authenticated API credentials
 */
function initializeApiRequest(array $config, PDO $pdo): array
{
    // CORS and preflight handling
    configureCors($config);
    handlePreflightRequest();

    // Extract and validate API key
    $apiKey = extractApiKey();
    $apiKeyId = authenticateApiKey($pdo, $config, $apiKey);

    // Rate limiting
    enforceRateLimit($pdo, $config, $apiKey);

    // Update last used timestamp
    updateApiKeyLastUsed($pdo, $config, $apiKeyId);

    return [
        'apiKey' => $apiKey,
        'apiKeyId' => $apiKeyId
    ];
}

/**
 * Parse and extract the API endpoint path from request URI.
 * 
 * @param string $prefix Path prefix to remove (e.g., '/api/' or '/api/event/')
 * @return string The cleaned endpoint path
 */
function parseEndpointPath(string $prefix): string
{
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);

    // Remove prefix and optional index.php
    $pattern = '#^' . preg_quote($prefix, '#') . '(index\.php)?/?#';
    return preg_replace($pattern, '', $path);
}

/**
 * Parse and validate JSON input from request body.
 * 
 * @param int|null $maxSize Maximum allowed payload size (defaults to config value)
 * @return array|null Parsed JSON data, or null if empty
 */
function parseJsonBody(?int $maxSize = null): ?array
{
    $rawInput = file_get_contents('php://input');
    $maxSize ??= API_MAX_PAYLOAD_SIZE;

    return validateJsonInput($rawInput, $maxSize);
}

/**
 * Validate and sanitize a UUID key from input.
 * 
 * @param mixed $key The key value to validate
 * @return string|null Sanitized key or null if invalid
 */
function validateUuidKey(mixed $key): ?string
{
    if (!is_string($key)) {
        return null;
    }

    $sanitized = sanitizeInput($key, UUID_LENGTH);
    return validateKeyName($sanitized) ? $sanitized : null;
}

/**
 * Send a validation error response for missing/invalid keys.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param int $apiKeyId The API key ID for logging
 * @param string $endpoint The endpoint name for logging
 * @param string $message The error message
 */
function sendKeyValidationError(
    PDO $pdo,
    array $config,
    int $apiKeyId,
    string $endpoint,
    string $message = 'Invalid key format. Key must be a valid UUID v4 (lowercase)'
): never {
    logApiRequest($pdo, $config, $apiKeyId, $endpoint, 'POST', 400);
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => $message
    ]);
}


