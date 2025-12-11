<?php

declare(strict_types=1);

/**
 * Security Helper Functions
 * 
 * Core security utilities for input validation, sanitization,
 * rate limiting, and request protection.
 */

// Security Constants
const MAX_INPUT_LENGTH = 1000;
const MAX_JSON_SIZE = 1048576; // 1MB
const CSRF_TOKEN_BYTES = 32;
const BRUTE_FORCE_MAX_DELAY = 30;

/**
 * Add standard security headers to all responses.
 */
function addSecurityHeaders(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self' https://accounts.google.com https://www.googleapis.com; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // HSTS only over HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

/**
 * Enforce HTTPS connection by redirecting HTTP requests.
 */
function enforceHttps(): void
{
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        if (php_sapi_name() !== 'cli') {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect, true, 301);
            exit;
        }
    }
}

/**
 * Sanitize and validate input string.
 * 
 * @param string $input Input to sanitize
 * @param int $maxLength Maximum allowed length
 * @return string Sanitized input
 */
function sanitizeInput(string $input, int $maxLength = MAX_INPUT_LENGTH): string
{
    // Remove null bytes
    $input = str_replace("\0", '', $input);

    // Trim whitespace
    $input = trim($input);

    // Enforce length limit
    if (strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }

    return $input;
}

/**
 * Validate email address format.
 */
function validateEmail(string $email): bool
{
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate API key format (64 hex characters).
 */
function validateApiKeyFormat(string $apiKey): bool
{
    return preg_match('/^[a-f0-9]{64}$/i', $apiKey) === 1;
}

/**
 * Validate session ID format (64 hex characters).
 */
function validateSessionIdFormat(string $sessionId): bool
{
    return preg_match('/^[a-f0-9]{64}$/i', $sessionId) === 1;
}

/**
 * Validate UUID v4 key format (lowercase).
 */
function validateKeyName(string $key): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key) === 1;
}

/**
 * Timing-safe string comparison (wrapper for readability).
 */
function timingSafeEquals(string $a, string $b): bool
{
    return hash_equals($a, $b);
}

/**
 * Generate a cryptographically secure random token.
 */
function generateSecureToken(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Regenerate session ID to prevent session fixation.
 */
function regenerateSessionId(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Validate IP address format.
 */
function validateIpAddress(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Check if request origin is in the allowed list.
 * 
 * @param string $origin Origin header value
 * @param string[] $allowedOrigins List of allowed origins
 * @return bool True if origin is allowed
 */
function isAllowedOrigin(string $origin, array $allowedOrigins): bool
{
    if ($origin === '' || empty($allowedOrigins)) {
        return false;
    }

    return in_array($origin, $allowedOrigins, true);
}

/**
 * Log security event for monitoring.
 * 
 * @param string $event Event name/type
 * @param array $context Additional context data
 */
function logSecurityEvent(string $event, array $context = []): void
{
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => getClientIpAddress(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'context' => $context
    ];

    error_log('SECURITY: ' . json_encode($logEntry));
}

/**
 * Validate and parse JSON input.
 * 
 * @param string $json JSON string to validate
 * @param int $maxSize Maximum allowed size in bytes
 * @return array|null Parsed data or null on failure
 */
function validateJsonInput(string $json, int $maxSize = MAX_JSON_SIZE): ?array
{
    if (strlen($json) > $maxSize) {
        return null;
    }

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

/**
 * Sanitize path to prevent directory traversal.
 * Handles null bytes, URL encoding, and various traversal patterns.
 */
function sanitizePath(string $path): string
{
    // Remove null bytes
    $path = str_replace("\0", '', $path);
    
    // Decode URL encoding to catch encoded traversal attempts
    $path = rawurldecode($path);
    
    // Remove all forms of parent directory traversal
    // Repeat until no more changes (handles nested patterns like ....// )
    do {
        $previous = $path;
        $path = preg_replace('#\.\.+[/\\\\]?#', '', $path);
        $path = str_replace(['../', '..\\'], '', $path);
    } while ($path !== $previous);
    
    return $path;
}

/**
 * Check and enforce rate limits.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $identifier Rate limit identifier (IP or key hash)
 * @param int $maxRequests Maximum requests per window
 * @param int $windowSeconds Window duration in seconds
 * @return bool True if request is allowed, false if rate limited
 */
function checkRateLimitWithKey(
    PDO $pdo,
    array $config,
    string $identifier,
    int $maxRequests = 100,
    int $windowSeconds = 60
): bool {
    $now = new DateTime();
    $windowStart = (clone $now)->modify("-{$windowSeconds} seconds");
    $prefix = getDbPrefix($config);

    // Cleanup old records periodically
    $cleanupTime = (clone $now)->modify('-120 seconds');
    $stmt = $pdo->prepare("DELETE FROM {$prefix}rate_limits WHERE window_start < ?");
    $stmt->execute([$cleanupTime->format('Y-m-d H:i:s')]);

    // Get or create rate limit record
    $stmt = $pdo->prepare("
        SELECT request_count, window_start 
        FROM {$prefix}rate_limits 
        WHERE ip_address = ?
    ");
    $stmt->execute([$identifier]);
    $record = $stmt->fetch();

    if ($record) {
        $recordWindowStart = new DateTime($record['window_start']);

        if ($recordWindowStart > $windowStart) {
            // Same window - check limit
            if ($record['request_count'] >= $maxRequests) {
                return false;
            }

            // Increment counter
            $stmt = $pdo->prepare("
                UPDATE {$prefix}rate_limits 
                SET request_count = request_count + 1 
                WHERE ip_address = ?
            ");
            $stmt->execute([$identifier]);
        } else {
            // New window - reset counter
            $stmt = $pdo->prepare("
                UPDATE {$prefix}rate_limits 
                SET request_count = 1, window_start = ? 
                WHERE ip_address = ?
            ");
            $stmt->execute([$now->format('Y-m-d H:i:s'), $identifier]);
        }
    } else {
        // Create new record
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}rate_limits (ip_address, request_count, window_start) 
            VALUES (?, 1, ?)
        ");
        $stmt->execute([$identifier, $now->format('Y-m-d H:i:s')]);
    }

    return true;
}

/**
 * Validate CSRF token against session.
 */
function validateCsrfToken(string $token): bool
{
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return timingSafeEquals($_SESSION['csrf_token'], $token);
}

/**
 * Probabilistic cleanup of expired sessions.
 * 
 * Runs approximately 1% of the time to avoid overhead.
 */
function cleanupExpiredSessionsAuto(PDO $pdo, array $config): void
{
    if (random_int(1, 100) === 1) {
        try {
            $prefix = getDbPrefix($config);
            $pdo->exec("DELETE FROM {$prefix}sessions WHERE expires_at <= NOW()");
        } catch (Exception $e) {
            // Silently fail - cleanup is not critical
        }
    }
}

/**
 * Validate HTTP request method.
 * 
 * Sends 405 Method Not Allowed if method is not in the allowed list.
 */
function validateRequestMethod(array $allowedMethods): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if (!in_array($method, $allowedMethods, true)) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowedMethods));
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Method not allowed'
        ]);
        exit;
    }
}

/**
 * Add delay for brute force protection (exponential backoff).
 * 
 * @param int $attempts Number of failed attempts
 */
function addBruteForceDelay(int $attempts): void
{
    $delay = min(pow(2, $attempts), BRUTE_FORCE_MAX_DELAY);
    sleep((int)$delay);
}

/**
 * Sanitize output for HTML context (XSS prevention).
 */
function sanitizeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize output for JavaScript context.
 */
function sanitizeJs(string $text): string
{
    return json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Validate numeric value is within allowed range.
 */
function validateRange(int $value, int $min, int $max): bool
{
    return $value >= $min && $value <= $max;
}

/**
 * Validate URL format.
 */
function validateUrl(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Clear session cookie securely.
 */
function clearSessionCookie(): void
{
    setcookie('session_id', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Set a secure session cookie.
 */
function setSecureSessionCookie(string $sessionId, int $expiresTimestamp): void
{
    setcookie('session_id', $sessionId, [
        'expires' => $expiresTimestamp,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

