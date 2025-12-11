<?php

declare(strict_types=1);

/**
 * Session Management Helpers
 * 
 * Shared session validation and management functions used across
 * the admin dashboard, landing page, and superadmin pages.
 */

// Session Constants
const SESSION_EXPIRY_HOURS = 24;
const SESSION_COOKIE_NAME = 'session_id';

/**
 * Start a PHP session with secure settings.
 */
function startSecureSession(): void
{
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');

    session_start();
}

/**
 * Set standard cache-control headers to prevent caching.
 */
function preventCaching(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Validate a session and return user data if valid.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $sessionId Session ID to validate
 * @return array|null Session data with email, photo_url, expires_at or null if invalid
 */
function validateUserSession(PDO $pdo, array $config, string $sessionId): ?array
{
    $prefix = getDbPrefix($config);

    // Check if photo_url column exists (backward compatibility)
    $hasPhotoColumn = checkPhotoUrlColumn($pdo, $prefix);

    if ($hasPhotoColumn) {
        $stmt = $pdo->prepare("
            SELECT email, photo_url, expires_at 
            FROM {$prefix}sessions 
            WHERE session_id = ? AND expires_at > NOW()
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT email, NULL as photo_url, expires_at 
            FROM {$prefix}sessions 
            WHERE session_id = ? AND expires_at > NOW()
        ");
    }

    $stmt->execute([$sessionId]);
    return $stmt->fetch() ?: null;
}

/**
 * Check if the sessions table has the photo_url column.
 * 
 * @param PDO $pdo Database connection
 * @param string $prefix Table prefix
 * @return bool True if column exists
 */
function checkPhotoUrlColumn(PDO $pdo, string $prefix): bool
{
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$prefix}sessions LIKE 'photo_url'");
        $hasColumn = $stmt->fetch() !== false;
    } catch (Exception $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

/**
 * Get authenticated user from session cookie.
 * 
 * Returns null if no valid session exists, otherwise returns session data.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @return array|null User session data or null
 */
function getAuthenticatedUser(PDO $pdo, array $config): ?array
{
    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? null;

    if ($sessionId === null) {
        return null;
    }

    if (!validateSessionIdFormat($sessionId)) {
        clearSessionCookie();
        return null;
    }

    $session = validateUserSession($pdo, $config, $sessionId);

    if ($session === null) {
        clearSessionCookie();
        return null;
    }

    return $session;
}

/**
 * Require authentication - redirect to login if not authenticated.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $redirectUrl URL to redirect to on failure
 * @return array User session data (never returns if not authenticated)
 */
function requireAuthentication(PDO $pdo, array $config, string $redirectUrl = '/'): array
{
    $session = getAuthenticatedUser($pdo, $config);

    if ($session === null) {
        header('Location: ' . $redirectUrl);
        exit;
    }

    return $session;
}

/**
 * Check if user is the superadmin.
 * 
 * @param string $userEmail User's email address
 * @param array $config Application configuration
 * @return bool True if user is superadmin
 */
function isSuperadmin(string $userEmail, array $config): bool
{
    $superadminEmail = $config['superadmin']['email'] ?? null;
    return $superadminEmail !== null && $userEmail === $superadminEmail;
}

/**
 * Get user avatar URL with fallback chain: Google -> Gravatar -> Mystery Person.
 * 
 * Wrapper around the getAvatarUrl function in config.php with clearer intent.
 * 
 * @param array $session Session data with photo_url and email
 * @param int $size Avatar size in pixels
 * @return string Avatar URL
 */
function getUserAvatarUrl(array $session, int $size = 96): string
{
    return getAvatarUrl($session['photo_url'] ?? null, $session['email'], $size);
}

/**
 * Build Google OAuth authorization URL.
 * 
 * @param array $googleOAuth Google OAuth configuration
 * @return string Authorization URL
 */
function buildGoogleAuthUrl(array $googleOAuth): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $googleOAuth['client_id'],
        'redirect_uri' => $googleOAuth['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'email profile',
        'access_type' => 'online',
        'prompt' => 'select_account'
    ]);
}

/**
 * Create a new session in the database.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $sessionId Generated session ID
 * @param string $email User email
 * @param string|null $photoUrl User photo URL (optional)
 * @param DateTime $expiresAt Session expiration time
 */
function createSession(
    PDO $pdo,
    array $config,
    string $sessionId,
    string $email,
    ?string $photoUrl,
    DateTime $expiresAt
): void {
    $prefix = getDbPrefix($config);
    $hasPhotoColumn = checkPhotoUrlColumn($pdo, $prefix);

    if ($hasPhotoColumn) {
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}sessions (session_id, email, photo_url, expires_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$sessionId, $email, $photoUrl, $expiresAt->format('Y-m-d H:i:s')]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}sessions (session_id, email, expires_at) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$sessionId, $email, $expiresAt->format('Y-m-d H:i:s')]);
    }
}

/**
 * Delete a session from the database.
 * 
 * @param PDO $pdo Database connection
 * @param array $config Application configuration
 * @param string $sessionId Session ID to delete
 */
function deleteSession(PDO $pdo, array $config, string $sessionId): void
{
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("DELETE FROM {$prefix}sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
}

/**
 * Generate CSRF token for forms.
 * 
 * @return string CSRF token
 */
function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}


