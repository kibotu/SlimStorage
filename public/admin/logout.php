<?php

declare(strict_types=1);

/**
 * Logout Handler
 * 
 * Destroys session and redirects to the landing page.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

// Security headers
addSecurityHeaders();

try {
    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? null;

    if ($sessionId !== null && validateSessionIdFormat($sessionId)) {
        $config = loadConfig();
        $pdo = getDatabaseConnection($config);

        deleteSession($pdo, $config, $sessionId);

        logSecurityEvent('user_logout', ['session_id' => substr($sessionId, 0, 8) . '...']);
    }

    clearSessionCookie();

} catch (Exception $e) {
    // Always clear cookie even on errors
    clearSessionCookie();
}

// Redirect to landing page
header('Location: /');
exit;

