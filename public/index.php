<?php

declare(strict_types=1);

/**
 * Landing Page
 * 
 * Public landing page with Google OAuth login.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';

// Security headers and cache prevention
addSecurityHeaders();
preventCaching();

// Start PHP session for CSRF protection
startSecureSession();

$isLoggedIn = false;
$userEmail = null;
$authUrl = null;
$errorMessage = null;
$config = [];

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);

    // Check for existing valid session
    $session = getAuthenticatedUser($pdo, $config);
    if ($session !== null) {
        $isLoggedIn = true;
        $userEmail = $session['email'];
    }

    // Build Google OAuth URL
    $googleOAuth = $config['google_oauth'] ?? null;
    if ($googleOAuth === null) {
        throw new Exception("Google OAuth not configured in .secrets.yml");
    }

    $authUrl = buildGoogleAuthUrl($googleOAuth);

} catch (Exception $e) {
    $errorMessage = htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SlimStorage - Secure Key/Value Store</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= htmlspecialchars(getBasePath()) ?>/css/style.css">
    <style>
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .site-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(8px);
            border-top: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            z-index: 10;
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            z-index: -1;
        }

        .orb-1 {
            width: 500px;
            height: 500px;
            background: rgba(59, 130, 246, 0.4);
            top: -100px;
            left: -100px;
            animation: float-1 25s infinite ease-in-out;
        }

        .orb-2 {
            width: 400px;
            height: 400px;
            background: rgba(236, 72, 153, 0.3);
            bottom: -100px;
            right: -100px;
            animation: float-2 30s infinite ease-in-out;
        }

        @keyframes float-1 {
            0%, 100% { 
                transform: translate(0, 0) scale(1) rotate(0deg); 
            }
            25% { 
                transform: translate(120px, -80px) scale(1.15) rotate(5deg); 
            }
            50% { 
                transform: translate(200px, 50px) scale(1.25) rotate(-3deg); 
            }
            75% { 
                transform: translate(80px, -120px) scale(0.9) rotate(8deg); 
            }
        }

        @keyframes float-2 {
            0%, 100% { 
                transform: translate(0, 0) scale(1) rotate(0deg); 
            }
            25% { 
                transform: translate(-100px, 120px) scale(0.85) rotate(-6deg); 
            }
            50% { 
                transform: translate(-180px, -60px) scale(0.75) rotate(4deg); 
            }
            75% { 
                transform: translate(-60px, 140px) scale(1.1) rotate(-7deg); 
            }
        }

        .login-card {
            width: 100%;
            max-width: 480px;
            padding: 3rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .app-logo {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            display: inline-block;
            animation: pulse 3s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.9; }
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.05em;
        }

        .hero-subtitle {
            color: var(--text-secondary);
            font-size: 1.125rem;
            margin-bottom: 2.5rem;
        }

        .google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: #1f2937;
            width: 100%;
            padding: 1rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.2s;
            border: 2px solid transparent;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .google-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(255, 255, 255, 0.1);
            background: #f9fafb;
        }

        .features-list {
            text-align: left;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .feature-icon {
            color: var(--success);
            background: rgba(16, 185, 129, 0.1);
            padding: 0.25rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .login-card {
                padding: 1.5rem;
                margin: 1rem;
                max-width: 100%;
            }

            .hero-title {
                font-size: 2rem;
            }

            .app-logo {
                font-size: 3rem;
                margin-bottom: 1rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <div class="card login-card">
        <div class="app-logo">🔐</div>
        <h1 class="hero-title">SlimStorage</h1>
        <p class="hero-subtitle">Secure Key/Value Store for Developers</p>

        <?php if ($errorMessage !== null): ?>
            <div class="alert alert-danger">⚠️ <?= $errorMessage ?></div>
        <?php endif; ?>

        <?php if ($isLoggedIn): ?>
            <div class="alert alert-success mb-4" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2);">
                <div class="flex items-center gap-2 justify-center">
                    <span>✓</span>
                    <span>Logged in as <strong><?= htmlspecialchars($userEmail) ?></strong></span>
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">
                    🚀 Go to Dashboard
                </a>
                <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/logout.php" class="btn btn-ghost" style="width: 100%;">
                    Sign Out
                </a>
            </div>
        <?php elseif ($authUrl !== null): ?>
            <a href="<?= htmlspecialchars($authUrl) ?>" class="google-btn">
                <svg width="24" height="24" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Continue with Google
            </a>

            <div class="text-muted text-sm mb-4">
                Secure, passwordless authentication. We only access your email.
            </div>
        <?php endif; ?>

        <div class="features-list">
            <div class="feature-item">
                <span class="feature-icon">✓</span>
                <span>Manage up to <?= getMaxKeysPerUser($config) ?> API keys</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">✓</span>
                <span>Key/Value storage + Event API</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">✓</span>
                <span>Time-series data with date range queries</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">✓</span>
                <span>Rate limited: <?= getRateLimitRequests($config) ?> req/<?= getRateLimitWindowSeconds($config) ?>s</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">✓</span>
                <span>Simple REST API</span>
            </div>
        </div>
    </div>
    
    <footer class="site-footer">
        <a href="https://github.com/kibotu/SlimStorage" target="_blank" rel="noopener">
            SlimStorage
        </a>
        <?php 
        $versionFile = __DIR__ . '/VERSION';
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : null;
        if ($version): 
        ?>
        <span class="version"><?= htmlspecialchars($version) ?></span>
        <?php endif; ?>
        <span class="separator">•</span>
        <a href="https://github.com/kibotu/SlimStorage" target="_blank" rel="noopener">
            GitHub
        </a>
        <span class="separator">•</span>
        <a href="https://github.com/kibotu/SlimStorage/issues" target="_blank" rel="noopener">
            Report Issue
        </a>
    </footer>
</body>
</html>

