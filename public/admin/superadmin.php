<?php

// declare(strict_types=1);

/**
 * Superadmin Dashboard - Full System Management
 * 
 * Restricted to superadmin email only. Provides system-wide analytics,
 * user management, and session control.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../session.php';

// Security headers and cache prevention
addSecurityHeaders();
preventCaching();

// Start secure PHP session
startSecureSession();

// Initialize default values for template variables
$config = [];
$errorMessage = null;
$userEmail = '';
$avatarUrl = '';
$userInitials = '';
$dbUrl = '';
$ftpUrl = '';
$superadminEmail = '';
$stats = ['total_users' => 0, 'total_api_keys' => 0, 'total_events' => 0, 'total_requests' => 0, 'active_sessions' => 0, 'events_table_size' => 0, 'total_db_size' => 0];
$apiKeys = [];
$globalStorageStats = [];
$apiUsageTimeline = [];
$topEndpoints = [];
$successRateStats = ['success_count' => 0, 'error_count' => 0, 'total_count' => 0];

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);

    // Validate superadmin configuration
    $superadminEmail = $config['superadmin']['email'] ?? null;
    if ($superadminEmail === null) {
        throw new Exception("Superadmin email not configured in .secrets.yml");
    }

    // External URLs from config
    $ftpUrl = $config['ftp']['url'] ?? '';
    $dbUrl = $config['database']['url'] ?? '';

    // Require authentication
    $session = getAuthenticatedUser($pdo, $config);
    if ($session === null) {
        logSecurityEvent('superadmin_access_denied', ['reason' => 'no_session']);
        header('Location: ' . getBasePath() . '/');
        exit;
    }

    // Auto-cleanup expired sessions
    cleanupExpiredSessionsAuto($pdo, $config);

    $userEmail = $session['email'];
    $photoUrl = $session['photo_url'] ?? null;
    $avatarUrl = getAvatarUrl($photoUrl, $userEmail);
    $userInitials = getUserInitials($userEmail);

    // Verify superadmin access
    if (!isSuperadmin($userEmail, $config)) {
        logSecurityEvent('superadmin_access_denied', ['reason' => 'not_superadmin', 'email' => $userEmail]);
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body style="font-family: system-ui; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #0f172a; color: #f8fafc;"><div style="text-align: center;"><h1>⛔ Access Denied</h1><p style="color: #94a3b8;">You do not have permission to access this page.</p><a href="' . htmlspecialchars(getBasePath()) . '/admin/" style="color: #3b82f6; text-decoration: none;">← Back to Dashboard</a></div></body></html>';
        exit;
    }

    logSecurityEvent('superadmin_access', ['email' => $userEmail]);

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $providedToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($providedToken)) {
            logSecurityEvent('csrf_token_mismatch', ['email' => $userEmail, 'page' => 'superadmin']);
            throw new Exception("Invalid CSRF token");
        }

        $action = $_POST['action'] ?? null;

        match ($action) {
            'delete_session' => isset($_POST['session_id']) && deleteSessionById($pdo, $config, $_POST['session_id']),
            'delete_all_sessions' => isset($_POST['email']) && deleteAllUserSessions($pdo, $config, $_POST['email']),
            'delete_api_key' => isset($_POST['api_key_id']) && deleteApiKeyAdmin($pdo, $config, (int)$_POST['api_key_id']),
            'delete_user' => isset($_POST['email']) && deleteUserCompletely($pdo, $config, $_POST['email']),
            'cleanup_expired' => cleanupExpiredSessions($pdo, $config),
            default => null
        };

        header('Location: ' . getBasePath() . '/admin/superadmin.php');
        exit;
    }

    // Fetch dashboard data
    $stats = getSystemStats($pdo, $config);
    $sessions = getAllSessions($pdo, $config);
    $accounts = getAllAccounts($pdo, $config);
    $apiKeys = getAllApiKeys($pdo, $config);
    $todayRequests = getTodayRequests($pdo, $config);
    $apiUsageStats = getApiUsageStats($pdo, $config);
    $globalStorageStats = getGlobalStorageStats($pdo, $config);
    $apiUsageTimeline = getApiUsageTimeline($pdo, $config);
    $topEndpoints = getTopEndpoints($pdo, $config);
    $successRateStats = getSuccessRateStats($pdo, $config);

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

/**
 * Get CSRF token (wrapper for template use).
 */
function getCSRFToken(): string
{
    return generateCsrfToken();
}

/**
 * Format bytes into human-readable format (B, KB, MB, GB).
 */
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) {
        return sprintf("%.2f GB", $bytes / 1073741824);
    } elseif ($bytes >= 1048576) {
        return sprintf("%.2f MB", $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf("%.2f KB", $bytes / 1024);
    }
    return $bytes . ' B';
}

function getSystemStats(PDO $pdo, array $config): array {
    $stats = [];
    $prefix = getDbPrefix($config);
    
    // Total accounts (unique emails with API keys)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT email) as count FROM {$prefix}api_keys");
    $stats['total_accounts'] = $stmt->fetch()['count'];
    
    // Total API keys
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$prefix}api_keys");
    $stats['total_api_keys'] = $stmt->fetch()['count'];
    
    // Active sessions
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$prefix}sessions WHERE expires_at > NOW()");
    $stats['active_sessions'] = $stmt->fetch()['count'];
    
    // Total stored KV pairs
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$prefix}kv_store");
    $stats['total_kv_pairs'] = $stmt->fetch()['count'];
    
    // Expired sessions (for cleanup)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$prefix}sessions WHERE expires_at <= NOW()");
    $stats['expired_sessions'] = $stmt->fetch()['count'];
    
    // Total events from pre-computed stats (O(1) instead of scanning 8M+ rows)
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(total_events), 0) as count FROM {$prefix}api_key_stats");
        $stats['total_events'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['total_events'] = 0;
    }
    
    // Get actual table sizes from MySQL information_schema (accurate, fast)
    try {
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME as name,
                TABLE_ROWS as row_count,
                DATA_LENGTH + INDEX_LENGTH as total_size
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_TYPE = 'BASE TABLE'
        ");
        $tables = $stmt->fetchAll();
        
        $totalDbSize = 0;
        $eventsTableSize = 0;
        
        foreach ($tables as $table) {
            $totalDbSize += (int)$table['total_size'];
            if ($table['name'] === "{$prefix}events") {
                $eventsTableSize = (int)$table['total_size'];
                // Use actual row count from information_schema if stats not available
                if ($stats['total_events'] === 0) {
                    $stats['total_events'] = (int)$table['row_count'];
                }
            }
        }
        
        $stats['events_table_size'] = $eventsTableSize;
        $stats['total_db_size'] = $totalDbSize;
    } catch (Exception $e) {
        $stats['events_table_size'] = 0;
        $stats['total_db_size'] = 0;
    }
    
    return $stats;
}

function getAllSessions(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT id, session_id, email, expires_at, created_at,
               CASE WHEN expires_at > NOW() THEN 'active' ELSE 'expired' END as status
        FROM {$prefix}sessions 
        ORDER BY created_at DESC
        LIMIT 100
    ");
    return $stmt->fetchAll();
}

function getAllAccounts(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            ak.email,
            COUNT(DISTINCT ak.id) as api_key_count,
            MIN(ak.created_at) as first_key_created,
            MAX(ak.last_used_at) as last_activity,
            COUNT(kv.id) as total_kv_pairs
        FROM {$prefix}api_keys ak
        LEFT JOIN {$prefix}kv_store kv ON kv.api_key_id = ak.id
        GROUP BY ak.email
        ORDER BY first_key_created DESC
    ");
    return $stmt->fetchAll();
}

function getAllApiKeys(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            ak.id,
            ak.api_key,
            ak.email,
            ak.created_at,
            ak.last_used_at,
            COUNT(kv.id) as kv_count
        FROM {$prefix}api_keys ak
        LEFT JOIN {$prefix}kv_store kv ON kv.api_key_id = ak.id
        GROUP BY ak.id, ak.api_key, ak.email, ak.created_at, ak.last_used_at
        ORDER BY ak.created_at DESC
        LIMIT 200
    ");
    return $stmt->fetchAll();
}

function getTodayRequests(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            ak.api_key,
            ak.email,
            ak.last_used_at,
            COUNT(kv.id) as total_kv_pairs,
            CASE 
                WHEN ak.last_used_at >= CURDATE() THEN 'active_today'
                WHEN ak.last_used_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 'active_week'
                WHEN ak.last_used_at IS NOT NULL THEN 'inactive'
                ELSE 'never_used'
            END as activity_status
        FROM {$prefix}api_keys ak
        LEFT JOIN {$prefix}kv_store kv ON kv.api_key_id = ak.id
        GROUP BY ak.id, ak.api_key, ak.email, ak.last_used_at
        ORDER BY ak.last_used_at DESC
        LIMIT 200
    ");
    return $stmt->fetchAll();
}

function getApiUsageStats(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            ak.id,
            ak.api_key,
            ak.email,
            ak.last_used_at,
            COALESCE(kv_counts.cnt, 0) as total_kv_pairs,
            COALESCE(log_stats.total_requests, 0) as total_requests,
            COALESCE(log_stats.today_requests, 0) as today_requests,
            COALESCE(log_stats.week_requests, 0) as week_requests,
            COALESCE(log_stats.month_requests, 0) as month_requests
        FROM {$prefix}api_keys ak
        LEFT JOIN (
            SELECT api_key_id, COUNT(*) as cnt
            FROM {$prefix}kv_store
            GROUP BY api_key_id
        ) kv_counts ON kv_counts.api_key_id = ak.id
        LEFT JOIN (
            SELECT 
                api_key_id,
                COUNT(*) as total_requests,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today_requests,
                SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as week_requests,
                SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as month_requests
            FROM {$prefix}api_logs
            GROUP BY api_key_id
        ) log_stats ON log_stats.api_key_id = ak.id
        ORDER BY today_requests DESC, total_requests DESC
        LIMIT 200
    ");
    return $stmt->fetchAll();
}

function getApiUsageByEndpoint(PDO $pdo, array $config, int $apiKeyId): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("
        SELECT 
            endpoint,
            COUNT(*) as count,
            SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today_count
        FROM {$prefix}api_logs
        WHERE api_key_id = ?
        GROUP BY endpoint
        ORDER BY count DESC
    ");
    $stmt->execute([$apiKeyId]);
    return $stmt->fetchAll();
}

function deleteSessionById(PDO $pdo, array $config, string $sessionId): bool {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("DELETE FROM {$prefix}sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    return true;
}

function deleteAllUserSessions(PDO $pdo, array $config, string $email): bool {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("DELETE FROM {$prefix}sessions WHERE email = ?");
    $stmt->execute([$email]);
    return true;
}

function deleteApiKeyAdmin(PDO $pdo, array $config, int $apiKeyId): bool {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("DELETE FROM {$prefix}api_keys WHERE id = ?");
    $stmt->execute([$apiKeyId]);
    return true;
}

function deleteUserCompletely(PDO $pdo, array $config, string $email): bool {
    $prefix = getDbPrefix($config);
    // Delete all API keys (cascades to kv_store)
    $stmt = $pdo->prepare("DELETE FROM {$prefix}api_keys WHERE email = ?");
    $stmt->execute([$email]);

    // Delete all sessions
    $stmt = $pdo->prepare("DELETE FROM {$prefix}sessions WHERE email = ?");
    $stmt->execute([$email]);
    return true;
}

function cleanupExpiredSessions(PDO $pdo, array $config): bool {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->prepare("DELETE FROM {$prefix}sessions WHERE expires_at <= NOW()");
    $stmt->execute();
    return true;
}

function getGlobalStorageStats(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    
    // Get actual table size and total events to calculate proportional disk usage
    $tableSize = 0;
    $totalTableEvents = 0;
    try {
        $stmt = $pdo->query("
            SELECT 
                TABLE_ROWS as total_events,
                DATA_LENGTH + INDEX_LENGTH as table_size
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}events'
        ");
        $tableInfo = $stmt->fetch();
        if ($tableInfo) {
            $tableSize = (int)$tableInfo['table_size'];
            $totalTableEvents = (int)$tableInfo['total_events'];
        }
    } catch (Exception $e) {
        // Fall back to stats-based calculation
    }
    
    // Get per-API-key stats with proportional disk usage
    $stmt = $pdo->query("
        SELECT 
            ak.email,
            ak.api_key,
            ak.name,
            COALESCE(kv_stats.kv_bytes, 0) as kv_bytes,
            COALESCE(aks.total_events, 0) as event_count,
            COALESCE(aks.total_event_bytes, 0) as event_json_bytes
        FROM {$prefix}api_keys ak
        LEFT JOIN {$prefix}api_key_stats aks ON aks.api_key_id = ak.id
        LEFT JOIN (
            SELECT api_key_id, SUM(LENGTH(value)) as kv_bytes
            FROM {$prefix}kv_store
            GROUP BY api_key_id
        ) kv_stats ON kv_stats.api_key_id = ak.id
        ORDER BY event_count DESC
        LIMIT 50
    ");
    $results = $stmt->fetchAll();
    
    // Calculate proportional disk usage for each API key
    foreach ($results as &$row) {
        $kvBytes = (int)$row['kv_bytes'];
        $eventJsonBytes = (int)$row['event_json_bytes'];
        $eventCount = (int)$row['event_count'];
        
        // Payload bytes = actual data stored
        $row['payload_bytes'] = $kvBytes + $eventJsonBytes;
        
        // Disk bytes = proportional share of actual table size
        if ($tableSize > 0 && $totalTableEvents > 0 && $eventCount > 0) {
            $proportion = $eventCount / $totalTableEvents;
            $row['event_bytes'] = (int)round($tableSize * $proportion);
        } else {
            // Fallback to JSON bytes
            $row['event_bytes'] = $eventJsonBytes;
        }
        $row['total_bytes'] = $kvBytes + (int)$row['event_bytes'];
    }
    
    // Re-sort by total_bytes descending
    usort($results, fn($a, $b) => $b['total_bytes'] - $a['total_bytes']);
    
    return $results;
}

function getApiUsageTimeline(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_requests
        FROM {$prefix}api_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    return $stmt->fetchAll();
}

function getTopEndpoints(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            endpoint,
            COUNT(*) as count
        FROM {$prefix}api_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY endpoint
        ORDER BY count DESC
        LIMIT 10
    ");
    return $stmt->fetchAll();
}

function getSuccessRateStats(PDO $pdo, array $config): array {
    $prefix = getDbPrefix($config);
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_error_count,
            SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_error_count,
            COUNT(*) as total_count
        FROM {$prefix}api_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    return $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard | System Management</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= htmlspecialchars(getBasePath()) ?>/css/style.css">
    <style>
        .section { display: none; }
        .section.active { display: block; }
        
        /* Grid layouts for charts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            /* Chart containers mobile */
            #global-storage-chart,
            #global-api-usage-chart {
                height: 300px !important;
            }
            
            #top-endpoints-chart,
            #success-rate-chart,
            #global-dist-chart,
            #top-requests-chart {
                height: 250px !important;
            }
            
            #api-usage-timeline-chart {
                height: 280px !important;
            }
            
            /* Card adjustments */
            .card-body {
                padding: 0.75rem 0;
            }
            
            /* Header mobile fixes */
            .header-content .flex.items-center.gap-2 {
                gap: 0.25rem;
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            
            .header-content .flex.items-center.gap-2 .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }
            
            .header-content .flex.items-center.gap-2 .btn-icon {
                width: 28px;
                height: 28px;
            }
            
            /* Stats grid for superadmin */
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .grid-2 {
                gap: 0.75rem;
            }
            
            #global-storage-chart,
            #global-api-usage-chart {
                height: 250px !important;
            }
            
            #top-endpoints-chart,
            #success-rate-chart,
            #global-dist-chart,
            #top-requests-chart {
                height: 200px !important;
            }
            
            #api-usage-timeline-chart {
                height: 220px !important;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container header-content">
            <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/superadmin.php" class="logo">
                <span class="logo-icon">🛡️</span>
                <span class="hidden-mobile">Superadmin</span>
            </a>
            <div class="flex items-center gap-2">
                <span class="badge badge-superadmin hidden-mobile">ADMIN</span>
                <div class="user-info-wrapper hidden-mobile">
                    <span class="text-muted text-sm"><?= htmlspecialchars($userEmail) ?></span>
                </div>
                <img src="<?= htmlspecialchars($avatarUrl) ?>" 
                     alt="<?= htmlspecialchars($userEmail) ?>" 
                     class="user-avatar"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="user-avatar-fallback" style="display: none;">
                    <?= htmlspecialchars($userInitials) ?>
                </div>
                <button id="refreshToggle" class="btn btn-icon btn-sm" title="Toggle auto-refresh (5s)">
                    ▶️
                </button>
                <a href="<?= htmlspecialchars($dbUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm hidden-mobile" title="Database">
                    🗄️
                </a>
                <a href="<?= htmlspecialchars($ftpUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm hidden-mobile" title="FTP">
                    📁
                </a>
                <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/" class="btn btn-secondary btn-sm">←<span class="hidden-mobile"> User</span></a>
                <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/logout.php" class="btn btn-ghost btn-sm">Logout</a>
            </div>
        </div>
    </header>
    
    <main class="container animate-fade-in" style="padding-top: 2rem;">
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger">⚠️ <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Accounts</div>
                <div class="stat-value"><?= $stats['total_accounts'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total API Keys</div>
                <div class="stat-value"><?= $stats['total_api_keys'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Sessions</div>
                <div class="stat-value"><?= $stats['active_sessions'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Events</div>
                <div class="stat-value"><?= number_format($stats['total_events'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total KV Pairs</div>
                <div class="stat-value"><?= $stats['total_kv_pairs'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Events Table Size</div>
                <div class="stat-value"><?= formatBytes($stats['events_table_size'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total DB Size</div>
                <div class="stat-value"><?= formatBytes($stats['total_db_size'] ?? 0) ?></div>
            </div>
            <div class="stat-card <?= ($stats['expired_sessions'] ?? 0) > 0 ? 'warning' : '' ?>">
                <div class="stat-label">Expired Sessions</div>
                <div class="stat-value"><?= $stats['expired_sessions'] ?? 0 ?></div>
            </div>
        </div>
        
        <div class="tabs">
            <button class="tab-btn active" onclick="showSection('insights')">📈 <span class="hidden-mobile">Insights</span></button>
            <button class="tab-btn" onclick="showSection('sessions')">📋 <span class="hidden-mobile">Sessions</span></button>
            <button class="tab-btn" onclick="showSection('accounts')">👥 <span class="hidden-mobile">Accounts</span></button>
            <button class="tab-btn" onclick="showSection('apikeys')">🔑 <span class="hidden-mobile">API Keys</span></button>
        </div>
        
        <!-- Insights Section -->
        <div id="insights" class="section active">
            <!-- 1. Top Endpoints & Success Error Rate -->
            <div class="grid-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🎯 Top Endpoints</h3>
                    </div>
                    <div class="card-body">
                        <div id="top-endpoints-chart" style="width: 100%; height: 300px;"></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">✅ Success vs Error Rate</h3>
                    </div>
                    <div class="card-body">
                        <div id="success-rate-chart" style="width: 100%; height: 300px;"></div>
                    </div>
                </div>
            </div>

            <!-- 2. API Usage Trends -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">📊 API Usage Trends (Last 30 Days)</h3>
                </div>
                <div class="card-body">
                    <div id="api-usage-timeline-chart" style="width: 100%; height: 350px;"></div>
                </div>
            </div>

            <!-- 3. Global Data Distribution + Top Active Accounts -->
            <div class="grid-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">💾 Global Data Distribution</h3>
                    </div>
                    <div class="card-body">
                        <div id="global-dist-chart" style="width: 100%; height: 300px;"></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🔥 Top Active Accounts (Requests)</h3>
                    </div>
                    <div class="card-body">
                        <div id="top-requests-chart" style="width: 100%; height: 300px;"></div>
                    </div>
                </div>
            </div>

            <!-- 4. Most Valuable Accounts (Storage) -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">💎 Most Valuable Accounts (Storage)</h3>
                </div>
                <div class="card-body">
                    <div id="global-storage-chart" style="width: 100%; height: 400px;"></div>
                </div>
            </div>

            <!-- 5. Most Valuable Accounts (API Usage) -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title">🚀 Most Valuable Accounts (API Usage)</h3>
                </div>
                <div class="card-body">
                    <div id="global-api-usage-chart" style="width: 100%; height: 400px;"></div>
                </div>
            </div>
        </div>

        <!-- Sessions Section -->
        <div id="sessions" class="section">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Active Sessions</h3>
                    <?php if (($stats['expired_sessions'] ?? 0) > 0): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                            <input type="hidden" name="action" value="cleanup_expired">
                            <button type="submit" class="btn btn-primary btn-sm">
                                🧹 Cleanup <?= $stats['expired_sessions'] ?> Expired
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <?php if (empty($sessions)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">📭</span>
                            <p>No sessions found</p>
                        </div>
                    <?php else: ?>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Email</th>
                                    <th>Session ID</th>
                                    <th>Created</th>
                                    <th>Expires</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $s): ?>
                                    <tr>
                                        <td data-label="Status">
                                            <?php if ($s['status'] === 'active'): ?>
                                                <span class="badge badge-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Expired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Email" title="<?= htmlspecialchars($s['email']) ?>">
                                            <?= htmlspecialchars($s['email']) ?>
                                        </td>
                                        <td data-label="Session ID">
                                            <code class="text-muted"><?= htmlspecialchars(substr($s['session_id'], 0, 16)) ?>...</code>
                                        </td>
                                        <td data-label="Created" class="text-sm text-muted"><?= date('M j H:i', strtotime($s['created_at'])) ?></td>
                                        <td data-label="Expires" class="text-sm text-muted"><?= date('M j H:i', strtotime($s['expires_at'])) ?></td>
                                        <td data-label="Actions" class="text-right">
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this session?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                                <input type="hidden" name="action" value="delete_session">
                                                <input type="hidden" name="session_id" value="<?= htmlspecialchars($s['session_id']) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Accounts Section -->
        <div id="accounts" class="section">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Registered Accounts</h3>
                </div>
                <div class="table-responsive">
                    <?php if (empty($accounts)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">👤</span>
                            <p>No accounts found</p>
                        </div>
                    <?php else: ?>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>API Keys</th>
                                    <th>KV Pairs</th>
                                    <th>First Created</th>
                                    <th>Last Activity</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td data-label="Email" title="<?= htmlspecialchars($acc['email']) ?>">
                                            <?= htmlspecialchars($acc['email']) ?>
                                            <?php if ($acc['email'] === $superadminEmail): ?>
                                                <span class="badge badge-superadmin" style="font-size: 0.65rem; padding: 0.2rem 0.5rem; margin-left: 0.5rem;">SA</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="API Keys"><?= $acc['api_key_count'] ?></td>
                                        <td data-label="KV Pairs"><?= $acc['total_kv_pairs'] ?></td>
                                        <td data-label="First Created" class="text-sm text-muted"><?= date('M j, Y', strtotime($acc['first_key_created'])) ?></td>
                                        <td data-label="Last Activity" class="text-sm text-muted">
                                            <?= $acc['last_activity'] ? date('M j H:i', strtotime($acc['last_activity'])) : 'Never' ?>
                                        </td>
                                        <td data-label="Actions" class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete ALL sessions for this user?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                                    <input type="hidden" name="action" value="delete_all_sessions">
                                                    <input type="hidden" name="email" value="<?= htmlspecialchars($acc['email']) ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm">Logout All</button>
                                                </form>
                                                <?php if ($acc['email'] !== $superadminEmail): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ DELETE this user and ALL their data? This cannot be undone!');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="email" value="<?= htmlspecialchars($acc['email']) ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">Delete User</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- API Keys & Usage Section -->
        <div id="apikeys" class="section">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🔑 API Keys & Usage Monitor</h3>
                    <span class="text-muted text-sm"><?= date('l, M j, Y') ?> • Rate Limit: <?= getRateLimitRequests($config) ?> req/<?= getRateLimitWindowSeconds($config) ?>s</span>
                </div>
                <div class="table-responsive">
                    <?php if (empty($apiUsageStats)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">🔐</span>
                            <p>No API keys found</p>
                        </div>
                    <?php else: ?>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>API Key</th>
                                    <th>Owner</th>
                                    <th>Today</th>
                                    <th>This Week</th>
                                    <th>This Month</th>
                                    <th>Total</th>
                                    <th>KV Pairs</th>
                                    <th>Created</th>
                                    <th>Last Used</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apiUsageStats as $usage): ?>
                                    <tr>
                                        <td data-label="ID" class="text-muted">#<?= $usage['id'] ?></td>
                                        <td data-label="API Key">
                                            <code class="api-key-display"><?= htmlspecialchars($usage['api_key']) ?></code>
                                        </td>
                                        <td data-label="Owner" title="<?= htmlspecialchars($usage['email']) ?>">
                                            <?= htmlspecialchars($usage['email']) ?>
                                        </td>
                                        <td data-label="Today Requests">
                                            <?php if ($usage['today_requests'] > 0): ?>
                                                <span class="badge badge-success"><?= number_format($usage['today_requests']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Week Requests">
                                            <?php if ($usage['week_requests'] > 0): ?>
                                                <strong><?= number_format($usage['week_requests']) ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Month Requests">
                                            <?php if ($usage['month_requests'] > 0): ?>
                                                <?= number_format($usage['month_requests']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Total Requests">
                                            <strong><?= number_format($usage['total_requests']) ?></strong>
                                        </td>
                                        <td data-label="KV Pairs" class="text-muted"><?= number_format($usage['total_kv_pairs']) ?></td>
                                        <td data-label="Created" class="text-sm text-muted">
                                            <?php
                                                // Get created_at from apiKeys array
                                                $created_at = null;
                                                foreach ($apiKeys as $key) {
                                                    if ($key['id'] == $usage['id']) {
                                                        $created_at = $key['created_at'];
                                                        break;
                                                    }
                                                }
                                                echo $created_at ? date('M j H:i', strtotime($created_at)) : 'N/A';
                                            ?>
                                        </td>
                                        <td data-label="Last Used" class="text-sm text-muted">
                                            <?php if ($usage['last_used_at']): ?>
                                                <?php
                                                    $diff = time() - strtotime($usage['last_used_at']);
                                                    if ($diff < 60) echo $diff . 's ago';
                                                    elseif ($diff < 3600) echo floor($diff/60) . 'm ago';
                                                    elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                                                    else echo date('M j', strtotime($usage['last_used_at']));
                                                ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions" class="text-right">
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this API key? All associated data will be removed.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                                <input type="hidden" name="action" value="delete_api_key">
                                                <input type="hidden" name="api_key_id" value="<?= $usage['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script src="<?= htmlspecialchars(getBasePath()) ?>/js/d3.v7.min.js"></script>
    <script>
        // State
        let autoRefreshInterval = null;
        let isAutoRefreshActive = false;
        let chartsRendered = false;

        // Helper for formatting bytes
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Chart Rendering Function
        function renderCharts() {
            if (chartsRendered) return;
            
            const container = document.getElementById('global-storage-chart');
            if (!container || container.clientWidth === 0) return; // Hidden or not ready

            chartsRendered = true;

            // Data from PHP
            const storageStats = <?= json_encode($globalStorageStats) ?>;
            const usageStats = <?= json_encode($apiUsageStats) ?>;
            const usageTimeline = <?= json_encode($apiUsageTimeline) ?>;
            const topEndpoints = <?= json_encode($topEndpoints) ?>;
            const successRateStats = <?= json_encode($successRateStats) ?>;
            
            // Tooltip
            const tooltip = d3.select("body").append("div")
                .attr("class", "d3-tooltip")
                .style("opacity", 0)
                .style("position", "absolute")
                .style("background", "rgba(17, 24, 39, 0.95)")
                .style("color", "#f3f4f6")
                .style("padding", "10px 14px")
                .style("border-radius", "8px")
                .style("font-size", "13px")
                .style("pointer-events", "none")
                .style("box-shadow", "0 10px 15px -3px rgba(0, 0, 0, 0.1)")
                .style("z-index", "100")
                .style("border", "1px solid rgba(255, 255, 255, 0.1)");

            // --- 1. Global Storage Chart (Horizontal Bar) ---
            if (storageStats && storageStats.length > 0) {
                const margin = {top: 20, right: 100, bottom: 30, left: 220};
                const width = container.clientWidth - margin.left - margin.right;
                const height = container.clientHeight - margin.top - margin.bottom;

                const svg = d3.select("#global-storage-chart")
                    .append("svg")
                    .attr("width", width + margin.left + margin.right)
                    .attr("height", height + margin.top + margin.bottom)
                    .append("g")
                    .attr("transform", `translate(${margin.left},${margin.top})`);

                const topStorage = storageStats.slice(0, 15);

                const x = d3.scaleLinear()
                    .domain([0, d3.max(topStorage, d => d.total_bytes)])
                    .range([0, width]);

                const y = d3.scaleBand()
                    .range([0, height])
                    .domain(topStorage.map(d => d.email + ' - ' + d.api_key.substring(0,8)))
                    .padding(0.3);

                svg.append("g")
                    .attr("transform", `translate(0,${height})`)
                    .call(d3.axisBottom(x).ticks(5).tickFormat(d => formatBytes(d)))
                    .attr("color", "#6b7280")
                    .select(".domain").remove();

                svg.append("g")
                    .call(d3.axisLeft(y).tickFormat(d => d.split(' - ')[0]))
                    .attr("color", "#9ca3af")
                    .style("font-size", "12px")
                    .select(".domain").remove();

                svg.selectAll("myRect")
                    .data(topStorage)
                    .join("rect")
                    .attr("x", x(0))
                    .attr("y", d => y(d.email + ' - ' + d.api_key.substring(0,8)))
                    .attr("width", d => x(d.total_bytes))
                    .attr("height", y.bandwidth())
                    .attr("fill", "#8b5cf6")
                    .attr("rx", 4)
                    .on("mouseover", function(event, d) {
                        d3.select(this).attr("fill", "#a78bfa");
                        tooltip.transition().duration(200).style("opacity", 1);
                        tooltip.html(`
                            <div style="font-weight: 700; margin-bottom: 4px; color: #fff;">${d.email}</div>
                            <div style="font-size: 0.9em; color: #d1d5db; margin-bottom: 8px;">Key: ${d.api_key.substring(0,16)}...</div>
                            <div style="display: flex; justify-content: space-between; gap: 16px;">
                                <span>Payload:</span> <span style="font-weight: 600; color: #22c55e;">${formatBytes(d.payload_bytes || 0)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 16px; margin-top: 4px;">
                                <span>Storage:</span> <span style="font-weight: 600; color: #a78bfa;">${formatBytes(d.total_bytes)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 16px; font-size: 0.9em; color: #9ca3af; margin-top: 8px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 8px;">
                                <span>Events:</span> <span>${(d.event_count || 0).toLocaleString()}</span>
                            </div>
                        `)
                        .style("left", (event.pageX + 15) + "px")
                        .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).attr("fill", "#8b5cf6");
                        tooltip.transition().duration(500).style("opacity", 0);
                    });

                svg.selectAll("labels")
                    .data(topStorage)
                    .join("text")
                    .attr("x", d => x(d.total_bytes) + 5)
                    .attr("y", d => y(d.email + ' - ' + d.api_key.substring(0,8)) + y.bandwidth() / 2 + 4)
                    .text(d => formatBytes(d.total_bytes))
                    .style("fill", "#d1d5db")
                    .style("font-size", "11px");
            } else {
                document.getElementById('global-storage-chart').innerHTML = '<div class="empty-state"><p>No storage data available</p></div>';
            }

            // --- 2. Global API Usage Chart (Horizontal Bar) ---
            if (usageStats && usageStats.length > 0) {
                const apiUsageContainer = document.getElementById('global-api-usage-chart');
                const apiMargin = {top: 20, right: 100, bottom: 30, left: 220};
                const apiWidth = apiUsageContainer.clientWidth - apiMargin.left - apiMargin.right;
                const apiHeight = apiUsageContainer.clientHeight - apiMargin.top - apiMargin.bottom;

                const apiSvg = d3.select("#global-api-usage-chart")
                    .append("svg")
                    .attr("width", apiWidth + apiMargin.left + apiMargin.right)
                    .attr("height", apiHeight + apiMargin.top + apiMargin.bottom)
                    .append("g")
                    .attr("transform", `translate(${apiMargin.left},${apiMargin.top})`);

                // Sort by total requests and get top 15
                const topApiUsage = [...usageStats]
                    .sort((a, b) => b.total_requests - a.total_requests)
                    .slice(0, 15);

                const apiX = d3.scaleLinear()
                    .domain([0, d3.max(topApiUsage, d => d.total_requests)])
                    .range([0, apiWidth]);

                const apiY = d3.scaleBand()
                    .range([0, apiHeight])
                    .domain(topApiUsage.map(d => d.email + ' - ' + d.api_key.substring(0,8)))
                    .padding(0.3);

                apiSvg.append("g")
                    .attr("transform", `translate(0,${apiHeight})`)
                    .call(d3.axisBottom(apiX).ticks(5).tickFormat(d => d.toLocaleString()))
                    .attr("color", "#6b7280")
                    .select(".domain").remove();

                apiSvg.append("g")
                    .call(d3.axisLeft(apiY).tickFormat(d => d.split(' - ')[0]))
                    .attr("color", "#9ca3af")
                    .style("font-size", "12px")
                    .select(".domain").remove();

                apiSvg.selectAll("myRect")
                    .data(topApiUsage)
                    .join("rect")
                    .attr("x", apiX(0))
                    .attr("y", d => apiY(d.email + ' - ' + d.api_key.substring(0,8)))
                    .attr("width", d => apiX(d.total_requests))
                    .attr("height", apiY.bandwidth())
                    .attr("fill", "#10b981")
                    .attr("rx", 4)
                    .on("mouseover", function(event, d) {
                        d3.select(this).attr("fill", "#34d399");
                        tooltip.transition().duration(200).style("opacity", 1);
                        tooltip.html(`
                            <div style="font-weight: 700; margin-bottom: 4px; color: #fff;">${d.email}</div>
                            <div style="font-size: 0.9em; color: #d1d5db; margin-bottom: 8px;">Key: ${d.api_key.substring(0,16)}...</div>
                            <div style="display: flex; justify-content: space-between; gap: 16px;">
                                <span>Total Requests:</span> <span style="font-weight: 600; color: #34d399;">${parseInt(d.total_requests).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 16px; font-size: 0.9em; color: #9ca3af; margin-top: 4px;">
                                <span>Today:</span> <span>${parseInt(d.today_requests).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 16px; font-size: 0.9em; color: #9ca3af;">
                                <span>This Week:</span> <span>${parseInt(d.week_requests).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 16px; font-size: 0.9em; color: #9ca3af;">
                                <span>This Month:</span> <span>${parseInt(d.month_requests).toLocaleString()}</span>
                            </div>
                        `)
                        .style("left", (event.pageX + 15) + "px")
                        .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).attr("fill", "#10b981");
                        tooltip.transition().duration(500).style("opacity", 0);
                    });

                apiSvg.selectAll("labels")
                    .data(topApiUsage)
                    .join("text")
                    .attr("x", d => apiX(d.total_requests) + 5)
                    .attr("y", d => apiY(d.email + ' - ' + d.api_key.substring(0,8)) + apiY.bandwidth() / 2 + 4)
                    .text(d => parseInt(d.total_requests).toLocaleString())
                    .style("fill", "#d1d5db")
                    .style("font-size", "11px");
            } else {
                document.getElementById('global-api-usage-chart').innerHTML = '<div class="empty-state"><p>No API usage data available</p></div>';
            }

            // --- 4. Global Distribution (Donut) ---
            if (storageStats && storageStats.length > 0) {
                const totalKv = storageStats.reduce((acc, curr) => acc + parseInt(curr.kv_bytes), 0);
                const totalEvents = storageStats.reduce((acc, curr) => acc + parseInt(curr.event_bytes), 0);
                const total = totalKv + totalEvents;

                if (total > 0) {
                    const container = document.getElementById('global-dist-chart');
                    const width = container.clientWidth;
                    const height = container.clientHeight;
                    const radius = Math.min(width, height) / 2 - 20;

                    const svg = d3.select("#global-dist-chart")
                        .append("svg")
                        .attr("width", width)
                        .attr("height", height)
                        .append("g")
                        .attr("transform", `translate(${width/2},${height/2})`);

                    const data = { 'KV Store': totalKv, 'Events': totalEvents };
                    const color = d3.scaleOrdinal().domain(Object.keys(data)).range(["#ec4899", "#3b82f6"]);

                    const pie = d3.pie().value(d => d[1]).sort(null);
                    const data_ready = pie(Object.entries(data));
                    const arc = d3.arc().innerRadius(radius * 0.6).outerRadius(radius);

                    svg.selectAll('slices')
                        .data(data_ready)
                        .join('path')
                        .attr('d', arc)
                        .attr('fill', d => color(d.data[0]))
                        .attr("stroke", "#111827")
                        .style("stroke-width", "2px")
                        .on("mouseover", function(event, d) {
                            d3.select(this).style("opacity", 0.8);
                            tooltip.transition().duration(200).style("opacity", 1);
                            const percent = ((d.data[1] / total) * 100).toFixed(1);
                            tooltip.html(`
                                <div style="font-weight: 600; margin-bottom: 4px; color:${color(d.data[0])}">${d.data[0]}</div>
                                <div>${formatBytes(d.data[1])} (${percent}%)</div>
                            `).style("left", (event.pageX + 10) + "px").style("top", (event.pageY - 28) + "px");
                        })
                        .on("mouseout", function() {
                            d3.select(this).style("opacity", 1);
                            tooltip.transition().duration(500).style("opacity", 0);
                        });
                        
                    svg.append("text")
                        .attr("text-anchor", "middle")
                        .attr("dy", "-0.5em")
                        .style("font-size", "14px")
                        .style("fill", "#9ca3af")
                        .text("Total Data");
                    svg.append("text")
                        .attr("text-anchor", "middle")
                        .attr("dy", "1.0em")
                        .style("font-size", "18px")
                        .style("font-weight", "bold")
                        .style("fill", "#f3f4f6")
                        .text(formatBytes(total));
                } else {
                    document.getElementById('global-dist-chart').innerHTML = '<div class="empty-state"><p>No data stored yet</p></div>';
                }
            }

             // --- 5. Top Requests (Bar) ---
            if (usageStats && usageStats.length > 0) {
                const topRequests = [...usageStats].sort((a, b) => b.total_requests - a.total_requests).slice(0, 10);
                
                if (topRequests.length > 0 && topRequests[0].total_requests > 0) {
                     const container = document.getElementById('top-requests-chart');
                     const margin = {top: 20, right: 20, bottom: 30, left: 40};
                     const width = container.clientWidth - margin.left - margin.right;
                     const height = container.clientHeight - margin.top - margin.bottom;
                     
                     const svg = d3.select("#top-requests-chart")
                        .append("svg")
                        .attr("width", width + margin.left + margin.right)
                        .attr("height", height + margin.top + margin.bottom)
                        .append("g")
                        .attr("transform", `translate(${margin.left},${margin.top})`);
                        
                    const x = d3.scaleBand()
                        .range([0, width])
                        .domain(topRequests.map(d => d.email))
                        .padding(0.2);
                        
                    const y = d3.scaleLinear()
                        .domain([0, d3.max(topRequests, d => d.total_requests)])
                        .range([height, 0]);
                        
                    svg.append("g")
                        .attr("transform", `translate(0,${height})`)
                        .call(d3.axisBottom(x).tickFormat(() => ''))
                        .attr("color", "#6b7280")
                        .select(".domain").remove();
                        
                    svg.append("g")
                        .call(d3.axisLeft(y).ticks(5))
                        .attr("color", "#6b7280")
                        .select(".domain").remove();
                        
                    svg.selectAll("bars")
                        .data(topRequests)
                        .join("rect")
                        .attr("x", d => x(d.email))
                        .attr("y", d => y(d.total_requests))
                        .attr("width", x.bandwidth())
                        .attr("height", d => height - y(d.total_requests))
                        .attr("fill", "#10b981")
                        .attr("rx", 4)
                         .on("mouseover", function(event, d) {
                            d3.select(this).attr("fill", "#34d399");
                            tooltip.transition().duration(200).style("opacity", 1);
                            tooltip.html(`
                                <div style="font-weight: 700; margin-bottom: 4px; color: #fff;">${d.email}</div>
                                <div style="color: #d1d5db;">Requests: <span style="color: #34d399; font-weight:bold;">${parseInt(d.total_requests).toLocaleString()}</span></div>
                                <div style="color: #9ca3af; font-size:0.9em;">Last Active: ${d.last_used_at ? d.last_used_at.substring(0,10) : 'Never'}</div>
                            `)
                            .style("left", (event.pageX + 10) + "px")
                            .style("top", (event.pageY - 28) + "px");
                        })
                        .on("mouseout", function() {
                            d3.select(this).attr("fill", "#10b981");
                            tooltip.transition().duration(500).style("opacity", 0);
                        });
                } else {
                    document.getElementById('top-requests-chart').innerHTML = '<div class="empty-state"><p>No request data available</p></div>';
                }
            }

            // --- 6. API Usage Timeline (Area Chart) ---
            if (usageTimeline && usageTimeline.length > 0) {
                const container = document.getElementById('api-usage-timeline-chart');
                const margin = {top: 20, right: 20, bottom: 40, left: 50};
                const width = container.clientWidth - margin.left - margin.right;
                const height = container.clientHeight - margin.top - margin.bottom;

                const svg = d3.select("#api-usage-timeline-chart")
                    .append("svg")
                    .attr("width", width + margin.left + margin.right)
                    .attr("height", height + margin.top + margin.bottom)
                    .append("g")
                    .attr("transform", `translate(${margin.left},${margin.top})`);

                const parseDate = d3.timeParse("%Y-%m-%d");
                const formatDate = d3.timeFormat("%b %d");
                usageTimeline.forEach(d => d.dateObj = parseDate(d.date));

                const x = d3.scaleTime()
                    .domain(d3.extent(usageTimeline, d => d.dateObj))
                    .range([0, width]);

                const y = d3.scaleLinear()
                    .domain([0, d3.max(usageTimeline, d => +d.total_requests) * 1.1])
                    .range([height, 0]);

                svg.append("g")
                    .attr("transform", `translate(0,${height})`)
                    .call(d3.axisBottom(x).ticks(6).tickFormat(formatDate))
                    .attr("color", "#6b7280")
                    .select(".domain").remove();

                svg.append("g")
                    .call(d3.axisLeft(y).ticks(5))
                    .attr("color", "#6b7280")
                    .select(".domain").remove();

                // Grid lines
                svg.append("g")
                    .attr("stroke", "rgba(255, 255, 255, 0.05)")
                    .attr("stroke-dasharray", "3,3")
                    .call(d3.axisLeft(y).ticks(5).tickSize(-width).tickFormat(""))
                    .select(".domain").remove();

                // Gradient
                const gradient = svg.append("defs")
                    .append("linearGradient")
                    .attr("id", "timelineGradient")
                    .attr("x1", "0%").attr("y1", "0%")
                    .attr("x2", "0%").attr("y2", "100%");
                gradient.append("stop").attr("offset", "0%").attr("stop-color", "#3b82f6").attr("stop-opacity", 0.5);
                gradient.append("stop").attr("offset", "100%").attr("stop-color", "#3b82f6").attr("stop-opacity", 0);

                // Area
                svg.append("path")
                    .datum(usageTimeline)
                    .attr("fill", "url(#timelineGradient)")
                    .attr("stroke", "#3b82f6")
                    .attr("stroke-width", 2)
                    .attr("d", d3.area()
                        .x(d => x(d.dateObj))
                        .y0(height)
                        .y1(d => y(d.total_requests))
                        .curve(d3.curveMonotoneX)
                    );

                // Interactive circles
                svg.selectAll("dot")
                    .data(usageTimeline)
                    .enter().append("circle")
                    .attr("cx", d => x(d.dateObj))
                    .attr("cy", d => y(d.total_requests))
                    .attr("r", 4)
                    .attr("fill", "#1f2937")
                    .attr("stroke", "#3b82f6")
                    .attr("stroke-width", 2)
                    .on("mouseover", function(event, d) {
                        d3.select(this).attr("r", 6);
                        tooltip.transition().duration(200).style("opacity", 1);
                        tooltip.html(`
                            <div style="font-weight: 600; margin-bottom: 4px;">${formatDate(d.dateObj)}</div>
                            <div style="display: flex; justify-content: space-between; gap: 12px;">
                                <span>Total:</span> <span style="font-weight: 600; color: #3b82f6;">${d.total_requests}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 0.9em; color: #9ca3af;">
                                <span>Success:</span> <span style="color: #34d399;">${d.success_requests}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 0.9em; color: #9ca3af;">
                                <span>Error:</span> <span style="color: #f87171;">${d.error_requests}</span>
                            </div>
                        `)
                        .style("left", (event.pageX + 10) + "px")
                        .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).attr("r", 4);
                        tooltip.transition().duration(500).style("opacity", 0);
                    });
            } else {
                document.getElementById('api-usage-timeline-chart').innerHTML = '<div class="empty-state"><p>No API usage data</p></div>';
            }

            // --- 7. Top Endpoints (Horizontal Bar) ---
            if (topEndpoints && topEndpoints.length > 0) {
                const container = document.getElementById('top-endpoints-chart');
                const margin = {top: 20, right: 20, bottom: 30, left: 120};
                const width = container.clientWidth - margin.left - margin.right;
                const height = container.clientHeight - margin.top - margin.bottom;

                const svg = d3.select("#top-endpoints-chart")
                    .append("svg")
                    .attr("width", width + margin.left + margin.right)
                    .attr("height", height + margin.top + margin.bottom)
                    .append("g")
                    .attr("transform", `translate(${margin.left},${margin.top})`);

                const x = d3.scaleLinear()
                    .domain([0, d3.max(topEndpoints, d => d.count)])
                    .range([0, width]);

                const y = d3.scaleBand()
                    .range([0, height])
                    .domain(topEndpoints.map(d => d.endpoint))
                    .padding(0.2);

                svg.append("g")
                    .attr("transform", `translate(0,${height})`)
                    .call(d3.axisBottom(x).ticks(5))
                    .attr("color", "#6b7280")
                    .select(".domain").remove();

                svg.append("g")
                    .call(d3.axisLeft(y))
                    .attr("color", "#9ca3af")
                    .style("font-size", "11px")
                    .select(".domain").remove();

                svg.selectAll("myRect")
                    .data(topEndpoints)
                    .join("rect")
                    .attr("x", x(0))
                    .attr("y", d => y(d.endpoint))
                    .attr("width", d => x(d.count))
                    .attr("height", y.bandwidth())
                    .attr("fill", "#f59e0b")
                    .attr("rx", 3)
                    .on("mouseover", function(event, d) {
                        d3.select(this).attr("fill", "#fbbf24");
                        tooltip.transition().duration(200).style("opacity", 1);
                        tooltip.html(`
                            <div style="font-weight: 600; margin-bottom: 4px;">${d.endpoint}</div>
                            <div style="color: #d1d5db;">Requests: <span style="color: #fbbf24; font-weight:bold;">${parseInt(d.count).toLocaleString()}</span></div>
                        `)
                        .style("left", (event.pageX + 10) + "px")
                        .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).attr("fill", "#f59e0b");
                        tooltip.transition().duration(500).style("opacity", 0);
                    });
            } else {
                document.getElementById('top-endpoints-chart').innerHTML = '<div class="empty-state"><p>No endpoint data</p></div>';
            }

            // --- 8. Success Rate (Donut) ---
            if (successRateStats && successRateStats.total_count > 0) {
                const container = document.getElementById('success-rate-chart');
                const width = container.clientWidth;
                const height = container.clientHeight;
                const radius = Math.min(width, height) / 2 - 20;

                const svg = d3.select("#success-rate-chart")
                    .append("svg")
                    .attr("width", width)
                    .attr("height", height)
                    .append("g")
                    .attr("transform", `translate(${width/2},${height/2})`);

                const data = {
                    'Success': parseInt(successRateStats.success_count),
                    'Client Error': parseInt(successRateStats.client_error_count),
                    'Server Error': parseInt(successRateStats.server_error_count)
                };
                
                const color = d3.scaleOrdinal()
                    .domain(Object.keys(data))
                    .range(["#10b981", "#f59e0b", "#ef4444"]);

                const pie = d3.pie().value(d => d[1]).sort(null);
                const data_ready = pie(Object.entries(data).filter(d => d[1] > 0));
                const arc = d3.arc().innerRadius(radius * 0.6).outerRadius(radius);

                svg.selectAll('slices')
                    .data(data_ready)
                    .join('path')
                    .attr('d', arc)
                    .attr('fill', d => color(d.data[0]))
                    .attr("stroke", "#111827")
                    .style("stroke-width", "2px")
                    .on("mouseover", function(event, d) {
                        d3.select(this).style("opacity", 0.8);
                        tooltip.transition().duration(200).style("opacity", 1);
                        const percent = ((d.data[1] / successRateStats.total_count) * 100).toFixed(1);
                        tooltip.html(`
                            <div style="font-weight: 600; margin-bottom: 4px; color:${color(d.data[0])}">${d.data[0]}</div>
                            <div>${parseInt(d.data[1]).toLocaleString()} requests (${percent}%)</div>
                        `).style("left", (event.pageX + 10) + "px").style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).style("opacity", 1);
                        tooltip.transition().duration(500).style("opacity", 0);
                    });

                // Center text
                const successRate = ((successRateStats.success_count / successRateStats.total_count) * 100).toFixed(1);
                svg.append("text")
                    .attr("text-anchor", "middle")
                    .attr("dy", "-0.5em")
                    .style("font-size", "14px")
                    .style("fill", "#9ca3af")
                    .text("Success Rate");
                svg.append("text")
                    .attr("text-anchor", "middle")
                    .attr("dy", "1.0em")
                    .style("font-size", "20px")
                    .style("font-weight", "bold")
                    .style("fill", "#10b981")
                    .text(successRate + "%");
            } else {
                document.getElementById('success-rate-chart').innerHTML = '<div class="empty-state"><p>No data available</p></div>';
            }
        }
        
        function toggleAutoRefresh() {
            const toggleBtn = document.getElementById('refreshToggle');
            isAutoRefreshActive = !isAutoRefreshActive;
            
            if (isAutoRefreshActive) {
                toggleBtn.classList.add('active');
                toggleBtn.innerHTML = '⏸️';
                toggleBtn.title = 'Pause auto-refresh';
                toggleBtn.style.background = 'rgba(59, 130, 246, 0.2)';
                toggleBtn.style.borderColor = '#3b82f6';
                toggleBtn.style.color = '#3b82f6';
                
                autoRefreshInterval = setInterval(() => {
                    window.location.reload();
                }, 5000);
            } else {
                toggleBtn.classList.remove('active');
                toggleBtn.innerHTML = '▶️';
                toggleBtn.title = 'Toggle auto-refresh (5s)';
                toggleBtn.style.background = '';
                toggleBtn.style.borderColor = '';
                toggleBtn.style.color = '';
                
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
            
            localStorage.setItem('autoRefreshActive', isAutoRefreshActive);
        }
        
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
            
            // Show selected section
            document.getElementById(sectionId).classList.add('active');
            
            // Find the button that calls this function and add active class
            const buttons = document.querySelectorAll('.tab-btn');
            for (let btn of buttons) {
                if (btn.getAttribute('onclick').includes(sectionId)) {
                    btn.classList.add('active');
                    break;
                }
            }
            
            // Render charts if we switched to insights
            if (sectionId === 'insights') {
                // Small timeout to ensure DOM is updated
                setTimeout(renderCharts, 10);
            }
            
            // Save active tab to localStorage
            localStorage.setItem('activeTab', sectionId);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Restore auto-refresh state
            const savedState = localStorage.getItem('autoRefreshActive');
            if (savedState === null || savedState === 'true') {
                toggleAutoRefresh();
            }
            
            // 2. Restore active tab
            const savedTab = localStorage.getItem('activeTab');
            if (savedTab) {
                showSection(savedTab);
            } else {
                // Default to insights if nothing saved, and ensure charts render
                renderCharts();
            }
            
            // Add click handler to toggle button
            document.getElementById('refreshToggle').addEventListener('click', toggleAutoRefresh);
        });
    </script>
    
    <footer class="site-footer">
        <a href="https://github.com/kibotu/SlimStorage" target="_blank" rel="noopener">
            SlimStorage
        </a>
        <?php 
        $versionFile = __DIR__ . '/../VERSION';
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : null;
        if ($version): 
            // Check for newer version (cached, checks GitHub API once per hour)
            $latestVersion = null;
            $cacheFile = __DIR__ . '/../.version-cache';
            $cacheMaxAge = 3600; // 1 hour
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
                $latestVersion = trim(file_get_contents($cacheFile));
            } else {
                // Fetch latest version from GitHub API
                $ch = curl_init('https://api.github.com/repos/kibotu/SlimStorage/releases/latest');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'SlimStorage');
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200 && $response) {
                    $data = json_decode($response, true);
                    if (!empty($data['tag_name'])) {
                        $latestVersion = $data['tag_name'];
                        @file_put_contents($cacheFile, $latestVersion);
                    }
                }
            }
            
            $hasUpdate = $latestVersion && version_compare(ltrim($latestVersion, 'v'), ltrim($version, 'v'), '>');
        ?>
        <span class="version"><?= htmlspecialchars($version) ?><?php if ($hasUpdate): ?> <a href="https://github.com/kibotu/SlimStorage/releases/latest" target="_blank" rel="noopener" style="color: #10b981;">(<?= htmlspecialchars($latestVersion) ?> available)</a><?php endif; ?></span>
        <?php endif; ?>
        <span class="separator">•</span>
        <a href="https://github.com/kibotu/SlimStorage/issues" target="_blank" rel="noopener">
            Report Issue
        </a>
    </footer>
</body>
</html>
