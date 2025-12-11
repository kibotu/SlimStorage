<?php

// declare(strict_types=1);

/**
 * Google OAuth Callback Handler
 * 
 * Exchanges authorization code for access token and creates a new session.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

// Security headers
addSecurityHeaders();

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);
    $googleOAuth = $config['google_oauth'] ?? throw new Exception("Google OAuth not configured");

    // Validate authorization code
    $code = $_GET['code'] ?? null;
    if ($code === null) {
        logSecurityEvent('oauth_missing_code');
        throw new Exception("Missing authorization code");
    }

    $code = sanitizeInput($code, 1000);

    // Exchange code for access token
    $tokenData = exchangeCodeForToken($code, $googleOAuth);

    // Get user info from Google
    $userInfo = getUserInfo($tokenData['access_token']);
    $email = $userInfo['email'] ?? throw new Exception("Email not provided by Google");
    $photoUrl = $userInfo['picture'] ?? null;

    // Validate email format
    if (!validateEmail($email)) {
        logSecurityEvent('oauth_invalid_email', ['email' => $email]);
        throw new Exception("Invalid email address");
    }

    logSecurityEvent('oauth_success', ['email' => $email]);

    // Create new session
    $sessionId = generateSessionId();
    $expiresAt = (new DateTime())->modify('+' . SESSION_EXPIRY_HOURS . ' hours');

    createSession($pdo, $config, $sessionId, $email, $photoUrl, $expiresAt);

    // Set secure session cookie
    setSecureSessionCookie($sessionId, $expiresAt->getTimestamp());

    // Redirect to admin dashboard
    header('Location: /admin/');
    exit;

} catch (Exception $e) {
    header('Location: /?error=' . urlencode($e->getMessage()));
    exit;
}

/**
 * Exchange OAuth authorization code for access token.
 * 
 * @param string $code Authorization code from Google
 * @param array $googleOAuth OAuth configuration
 * @return array Token data including access_token
 * @throws Exception On HTTP or token errors
 */
function exchangeCodeForToken(string $code, array $googleOAuth): array
{
    $tokenUrl = 'https://oauth2.googleapis.com/token';

    $postData = [
        'code' => $code,
        'client_id' => $googleOAuth['client_id'],
        'client_secret' => $googleOAuth['client_secret'],
        'redirect_uri' => $googleOAuth['redirect_uri'],
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Token exchange failed: " . $error);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Failed to exchange code for token: HTTP $httpCode");
    }

    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        throw new Exception("Access token not received");
    }

    return $data;
}

/**
 * Fetch user profile information from Google.
 * 
 * @param string $accessToken OAuth access token
 * @return array User info including email and picture
 * @throws Exception On HTTP errors
 */
function getUserInfo(string $accessToken): array
{
    $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';

    $ch = curl_init($userInfoUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Failed to get user info: " . $error);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Failed to get user info: HTTP $httpCode");
    }

    return json_decode($response, true);
}

