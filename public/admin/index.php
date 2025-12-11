<?php

// declare(strict_types=1);

/**
 * Admin Dashboard - API Key Management
 * Requires valid session
 */

require_once __DIR__ . '/../config.php';

// Add security headers
addSecurityHeaders();

// Prevent caching to ensure session state is always fresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Start session with secure settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

session_start();

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 300) { // Every 5 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Initialize default values for template variables
$errorMessage = null;
$userEmail = '';
$avatarUrl = '';
$userInitials = '';
$isSuperadmin = false;
$apiKeys = [];
$apiKeyCount = 0;
$maxKeys = 100;
$canGenerateMore = false;
$totalStorageBytes = 0;
$totalPayloadBytes = 0;
$hasAnyData = false;
$apiBaseUrl = '';
$latestApiKey = '';
$kvPairs = [];
$kvStats = ['total_keys' => 0, 'api_keys_with_data' => 0, 'total_storage_bytes' => 0];
$eventsMigrationNeeded = false;
$eventStats = ['total' => 0, 'today' => 0, 'active_sources' => 0];
$recentEvents = [];
$eventChartData = [];
$commonKeys = [];
$requestLogsCount = 0;
$requestLogsTotalPages = 0;
$uniqueEndpoints = [];
$logStatusStats = [];
$logFilters = ['api_key_id' => '', 'status_filter' => '', 'endpoint' => '', 'method' => '', 'date_from' => '', 'date_to' => ''];
$logsLimit = 50;
$logsOffset = 0;
$logsPage = 1;
$maxValueSize = 262144;
$maxValueSizeKB = 256;

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);
    
    // Check session
    $sessionId = $_COOKIE['session_id'] ?? null;
    if (!$sessionId) {
        logSecurityEvent('missing_session_cookie', ['page' => 'admin_dashboard']);
        header('Location: ' . getBasePath() . '/');
        exit;
    }
    
    // Validate session ID format
    if (!validateSessionIdFormat($sessionId)) {
        logSecurityEvent('invalid_session_format', ['page' => 'admin_dashboard']);
        header('Location: ' . getBasePath() . '/');
        exit;
    }
    
    $session = validateSession($pdo, $config, $sessionId);
    if (!$session) {
        logSecurityEvent('invalid_session', ['page' => 'admin_dashboard']);
        header('Location: ' . getBasePath() . '/');
        exit;
    }
    
    // Auto-cleanup expired sessions
    cleanupExpiredSessionsAuto($pdo, $config);
    
    $userEmail = $session['email'];
    $photoUrl = $session['photo_url'] ?? null;
    $avatarUrl = getAvatarUrl($photoUrl, $userEmail);
    $userInitials = getUserInitials($userEmail);
    
    // Check if user is superadmin (from config)
    $superadminEmail = $config['superadmin']['email'] ?? null;
    $isSuperadmin = ($superadminEmail && $userEmail === $superadminEmail);
    
    // Handle POST requests (API key generation/deletion)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF protection with timing-safe comparison
        $providedToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($providedToken)) {
            logSecurityEvent('csrf_token_mismatch', ['email' => $userEmail, 'page' => 'admin_dashboard']);
            throw new Exception("Invalid CSRF token");
        }
        
        $action = $_POST['action'] ?? null;
        
        if ($action === 'generate') {
            generateNewApiKey($pdo, $config, $userEmail);
            header('Location: ' . getBasePath() . '/admin/');
            exit;
        } elseif ($action === 'delete' && isset($_POST['api_key_id'])) {
            deleteApiKey($pdo, $config, $userEmail, (int)$_POST['api_key_id']);
            header('Location: ' . getBasePath() . '/admin/');
            exit;
        } elseif ($action === 'rename' && isset($_POST['api_key_id']) && isset($_POST['name'])) {
            renameApiKey($pdo, $config, $userEmail, (int)$_POST['api_key_id'], $_POST['name']);
            header('Location: ' . getBasePath() . '/admin/');
            exit;
        }
    }
    
    // Get all API keys for this user
    $apiKeys = getApiKeys($pdo, $config, $userEmail);
    $apiKeyCount = count($apiKeys);
    $maxKeys = getMaxKeysPerUser($config);
    $canGenerateMore = $apiKeyCount < $maxKeys;

    // Get stats filter
    $statsFilter = isset($_GET['stats_filter']) && $_GET['stats_filter'] !== '' ? (int)$_GET['stats_filter'] : null;

    // Get usage stats
    $usageStats = getUsageStats($pdo, $config, $userEmail, $statsFilter);
    
    // Get total storage (both payload and disk usage)
    $storageStats = getStorageStats($pdo, $config, $userEmail);
    $totalStorageBytes = $storageStats['disk_bytes'];
    $totalPayloadBytes = $storageStats['payload_bytes'];
    
    // Get latest API key for documentation
    $latestApiKey = !empty($apiKeys) ? $apiKeys[0]['api_key'] : 'YOUR_API_KEY';
    
    // Get base URL from config for documentation
    $apiBaseUrl = 'https://' . getDomainName($config) . '/api';
    
    // Check if any data is stored across all keys to determine if docs should be collapsed
    $totalStoredItems = array_sum(array_column($apiKeys, 'key_count'));
    
    // Check if any events are stored - use pre-computed stats (O(1) instead of O(31M))
    $prefix = getDbPrefix($config);
    $totalStoredEvents = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(stats.total_events), 0) as count 
            FROM {$prefix}api_key_stats stats
            JOIN {$prefix}api_keys k ON stats.api_key_id = k.id
            WHERE k.email = ?
        ");
        $stmt->execute([$userEmail]);
        $totalStoredEvents = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        // Stats table doesn't exist - assume there might be events (don't query to avoid timeout)
        // The actual count will be shown if migration is run
        $totalStoredEvents = -1; // Unknown
    }
    
    // Check if we have any data at all (requests, stored items, or events)
    $hasAnyData = ($usageStats['total_requests'] > 0) || ($totalStoredItems > 0) || ($totalStoredEvents > 0);
    
    $collapseDocs = ($totalStoredItems > 0 || $totalStoredEvents > 0);
    
    // --- Data for View Data Tab ---
    $maxValueSize = getMaxValueSizeBytes($config);
    $maxValueSizeKB = round($maxValueSize / 1024, 2);
    
    // Handle AJAX request for logs
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_logs'])) {
        header('Content-Type: application/json');
        
        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            
            $filters = [
                'api_key_id' => $_GET['api_key_id'] ?? '',
                'status_filter' => $_GET['status_filter'] ?? '',
                'endpoint' => $_GET['endpoint'] ?? '',
                'method' => $_GET['method'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? ''
            ];
            
            $logs = getApiLogs($pdo, $config, $userEmail, $limit, $offset, $filters);
            $totalCount = getApiLogsCount($pdo, $config, $userEmail, $filters);
            $totalPages = ceil($totalCount / $limit);
            
            echo json_encode([
                'status' => 'success',
                'logs' => $logs,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => $totalCount,
                    'limit' => $limit,
                    'has_more' => $page < $totalPages
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Handle AJAX request for dad joke (Playground)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_joke'])) {
        header('Content-Type: application/json');
        
        $joke = 'Why do programmers prefer dark mode? Because light attracts bugs!';
        
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'Accept: application/json',
                        'User-Agent: SlimStorage API Playground (' . ($config['superadmin']['contact_email'] ?? 'admin@example.com') . ')'
                    ],
                    'timeout' => 5
                ]
            ]);
            
            $response = @file_get_contents('https://icanhazdadjoke.com/', false, $context);
            if ($response !== false) {
                $data = json_decode($response, true);
                if (isset($data['joke'])) {
                    $joke = $data['joke'];
                }
            }
        } catch (Exception $e) {
            // Use fallback joke
        }
        
        echo json_encode(['joke' => $joke]);
        exit;
    }
    
    // Handle AJAX requests for stats refresh (Playground)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_stats'])) {
        header('Content-Type: application/json');
        
        try {
            // Refresh stats data
            $refreshedApiKeys = getApiKeys($pdo, $config, $userEmail);
            $refreshedUsageStats = getUsageStats($pdo, $config, $userEmail, $statsFilter);
            $refreshedStorageStats = getStorageStats($pdo, $config, $userEmail);
            $refreshedEventStats = getEventStats($pdo, $config, $userEmail, null);
            
            echo json_encode([
                'status' => 'success',
                'stats' => [
                    'api_key_count' => count($refreshedApiKeys),
                    'max_keys' => $maxKeys,
                    'stored_keys' => array_sum(array_column($refreshedApiKeys, 'key_count')),
                    'total_requests' => $refreshedUsageStats['total_requests'] ?? 0,
                    'payload_data' => formatBytes($refreshedStorageStats['payload_bytes']),
                    'total_storage' => formatBytes($refreshedStorageStats['disk_bytes']),
                    'event_total' => $refreshedEventStats['total'] ?? 0,
                    'event_today' => $refreshedEventStats['today'] ?? 0,
                    'event_sources' => $refreshedEventStats['active_sources'] ?? 0
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Handle AJAX requests for KV Store operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kv_action'])) {
        header('Content-Type: application/json');
        
        // CSRF validation for AJAX requests
        $providedToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($providedToken)) {
            logSecurityEvent('csrf_token_mismatch_ajax', ['email' => $userEmail, 'action' => 'kv_operation']);
            echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page.']);
            exit;
        }
        
        try {
            $kvAction = $_POST['kv_action'];
            $kvId = (int)($_POST['kv_id'] ?? 0);
            
            // Verify ownership
            $stmt = $pdo->prepare("
                SELECT kv.id, kv.api_key_id, kv.key, kv.value
                FROM {$prefix}kv_store kv
                JOIN {$prefix}api_keys ak ON kv.api_key_id = ak.id
                WHERE kv.id = ? AND ak.email = ?
            ");
            $stmt->execute([$kvId, $userEmail]);
            $kvPair = $stmt->fetch();
            
            if (!$kvPair) {
                echo json_encode(['status' => 'error', 'message' => 'Key not found or access denied']);
                exit;
            }
            
            if ($kvAction === 'update') {
                $newValue = $_POST['value'] ?? '';
                
                if (strlen($newValue) > $maxValueSize) {
                    echo json_encode(['status' => 'error', 'message' => "Value exceeds maximum size of {$maxValueSizeKB} KB"]);
                    exit;
                }
                
                $stmt = $pdo->prepare("UPDATE {$prefix}kv_store SET value = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newValue, $kvId]);
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Value updated successfully',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                exit;
                
            } elseif ($kvAction === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM {$prefix}kv_store WHERE id = ?");
                $stmt->execute([$kvId]);
                
                echo json_encode(['status' => 'success', 'message' => 'Key deleted successfully']);
                exit;
            }
            
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Handle export requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_action'])) {
        header('Content-Type: application/json');
        
        // CSRF validation for AJAX requests
        $providedToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($providedToken)) {
            logSecurityEvent('csrf_token_mismatch_ajax', ['email' => $userEmail, 'action' => 'export']);
            echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page.']);
            exit;
        }
        
        try {
            $exportAction = $_POST['export_action'];
            $filterApiKeyId = isset($_POST['filter_api_key_id']) && $_POST['filter_api_key_id'] !== '' ? (int)$_POST['filter_api_key_id'] : null;
            
            if ($exportAction === 'export_kv') {
                // Export Key-Value pairs
                $where = "WHERE ak.email = ?";
                $params = [$userEmail];
                
                if ($filterApiKeyId) {
                    $where .= " AND ak.id = ?";
                    $params[] = $filterApiKeyId;
                }
                
                $stmt = $pdo->prepare("
                    SELECT 
                        kv.key,
                        kv.value,
                        kv.created_at,
                        kv.updated_at,
                        ak.api_key,
                        ak.name as api_key_name
                    FROM {$prefix}kv_store kv
                    JOIN {$prefix}api_keys ak ON kv.api_key_id = ak.id
                    $where
                    ORDER BY kv.updated_at DESC
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Try to parse JSON values
                foreach ($data as &$row) {
                    $decoded = json_decode($row['value'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['value'] = $decoded;
                    }
                }
                
                $export = [
                    'export_type' => 'key_value_pairs',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'total_items' => count($data),
                    'filter_applied' => $filterApiKeyId ? true : false,
                    'data' => $data
                ];
                
                echo json_encode(['status' => 'success', 'export' => $export], JSON_PRETTY_PRINT);
                exit;
                
            } elseif ($exportAction === 'export_events') {
                // Export Events - LIMIT to prevent timeout/memory issues
                $exportLimit = 10000; // Max 10K events per export
                
                // Without API key filter, export could timeout on large datasets
                if (!$filterApiKeyId) {
                    echo json_encode(['status' => 'error', 'message' => 'Please select an API key to filter before exporting events. Exporting all events without a filter may timeout.']);
                    exit;
                }
                
                $where = "WHERE e.api_key_id = ?";
                $params = [$filterApiKeyId];
                $params[] = $exportLimit;
                
                $stmt = $pdo->prepare("
                    SELECT 
                        e.event_data,
                        e.event_timestamp,
                        k.api_key,
                        k.name as api_key_name
                    FROM {$prefix}events e
                    JOIN {$prefix}api_keys k ON e.api_key_id = k.id
                    $where
                    ORDER BY e.id DESC
                    LIMIT ?
                ");
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Parse JSON event data
                foreach ($data as &$row) {
                    $decoded = json_decode($row['event_data'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['event_data'] = $decoded;
                    }
                }
                
                $export = [
                    'export_type' => 'events',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'total_items' => count($data),
                    'max_items' => $exportLimit,
                    'filter_applied' => true, // Always true - we require API key filter for event exports
                    'note' => count($data) >= $exportLimit ? 'Export limited to ' . number_format($exportLimit) . ' most recent events. Use the API for larger exports.' : null,
                    'data' => $data
                ];
                
                echo json_encode(['status' => 'success', 'export' => $export], JSON_PRETTY_PRINT);
                exit;
            }
            
            echo json_encode(['status' => 'error', 'message' => 'Invalid export action']);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Get KV pairs
    $kvPairs = getAllKeyValuePairs($pdo, $config, $userEmail);
    $kvStats = getKeyValueStats($pdo, $config, $userEmail);
    
    // --- Data for Event Explorer Tab ---
    $filterApiKeyId = isset($_GET['event_key']) && $_GET['event_key'] !== '' ? (int)$_GET['event_key'] : null;
    
    // Check if performance tables exist (migration has been run)
    $eventsMigrationRun = false;
    try {
        $stmt = $pdo->query("SELECT 1 FROM {$prefix}api_key_stats LIMIT 1");
        $eventsMigrationRun = true;
    } catch (Exception $e) {
        $eventsMigrationRun = false;
    }
    
    // Default empty data
    $eventStats = ['total' => 0, 'today' => 0, 'active_sources' => 0];
    $eventChartData = [];
    $commonKeys = [];
    $recentEvents = [];
    $eventsMigrationNeeded = false;
    
    if ($eventsMigrationRun) {
        // Migration run - use optimized queries
        $eventStats = getEventStats($pdo, $config, $userEmail, $filterApiKeyId);
        $eventChartData = getEventVolumeHistory($pdo, $config, $userEmail, $filterApiKeyId);
        $commonKeys = getCommonEventKeys($pdo, $config, $userEmail, $filterApiKeyId);
        // Don't load recent events on initial page load - they're loaded when the tab is opened
        // This avoids the expensive ORDER BY id DESC query on 8M+ events
        $recentEvents = [];
    } else {
        // No migration - flag that it's needed (don't query events at all)
        $eventsMigrationNeeded = true;
    }
    
    // --- Data for Request Logs Tab ---
    $logFilters = [
        'api_key_id' => $_GET['log_key'] ?? '',
        'status_filter' => $_GET['log_status'] ?? '',
        'endpoint' => $_GET['log_endpoint'] ?? '',
        'method' => $_GET['log_method'] ?? '',
        'date_from' => $_GET['log_from'] ?? '',
        'date_to' => $_GET['log_to'] ?? ''
    ];
    $logsPage = max(1, (int)($_GET['log_page'] ?? 1));
    $logsLimit = 50;
    $logsOffset = ($logsPage - 1) * $logsLimit;
    
    $requestLogs = getApiLogs($pdo, $config, $userEmail, $logsLimit, $logsOffset, $logFilters);
    $requestLogsCount = getApiLogsCount($pdo, $config, $userEmail, $logFilters);
    $requestLogsTotalPages = ceil($requestLogsCount / $logsLimit);
    $uniqueEndpoints = getUniqueEndpoints($pdo, $config, $userEmail);
    $logStatusStats = getLogStatusStats($pdo, $config, $userEmail);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

function validateSession(PDO $pdo, array $config, string $sessionId): ?array {
    $prefix = getDbPrefix($config);
    
    // Check if photo_url column exists (for backward compatibility)
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$prefix}sessions LIKE 'photo_url'");
        $hasPhotoColumn = $stmt->fetch() !== false;
    } catch (Exception $e) {
        $hasPhotoColumn = false;
    }
    
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

function getCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function getApiKeys(PDO $pdo, array $config, string $email): array {
    $prefix = getDbPrefix($config);
    
    // Get actual table size for proportional disk usage calculation
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
        // Ignore
    }
    
    // Optimized query using pre-computed stats table (O(1) per key instead of O(n) events/kv)
    // Uses api_key_stats for both events AND KV pairs for maximum performance
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ak.id, ak.name, ak.api_key, ak.created_at, ak.last_used_at,
                COALESCE(stats.total_kv_pairs, 0) as key_count,
                COALESCE(stats.total_events, 0) as event_count,
                COALESCE(stats.total_kv_bytes, 0) as kv_storage_bytes,
                COALESCE(stats.total_event_bytes, 0) as event_storage_bytes
            FROM {$prefix}api_keys ak
            LEFT JOIN {$prefix}api_key_stats stats ON stats.api_key_id = ak.id
            WHERE ak.email = ?
            ORDER BY ak.created_at DESC
        ");
        $stmt->execute([$email]);
        $keys = $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback if stats table doesn't exist (but limit event scanning)
        $stmt = $pdo->prepare("
            SELECT id, name, api_key, created_at, last_used_at,
                   (SELECT COUNT(*) FROM {$prefix}kv_store WHERE api_key_id = {$prefix}api_keys.id) as key_count,
                   0 as event_count,
                   (SELECT COALESCE(SUM(LENGTH(value)), 0) FROM {$prefix}kv_store WHERE api_key_id = {$prefix}api_keys.id) as kv_storage_bytes,
                   0 as event_storage_bytes
            FROM {$prefix}api_keys 
            WHERE email = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$email]);
        $keys = $stmt->fetchAll();
    }
    
    // Calculate payload and disk storage for each key
    foreach ($keys as &$key) {
        $kvBytes = (int)$key['kv_storage_bytes'];
        $eventPayloadBytes = (int)$key['event_storage_bytes'];
        $eventCount = (int)$key['event_count'];
        
        // Payload bytes = actual data stored
        $key['payload_bytes'] = $kvBytes + $eventPayloadBytes;
        
        // Disk bytes = proportional share of actual table size
        if ($tableSize > 0 && $totalTableEvents > 0 && $eventCount > 0) {
            $proportion = $eventCount / $totalTableEvents;
            $eventDiskBytes = (int)round($tableSize * $proportion);
            $key['total_storage_bytes'] = $kvBytes + $eventDiskBytes;
        } else {
            // Fallback to payload bytes
            $key['total_storage_bytes'] = $key['payload_bytes'];
        }
    }
    
    return $keys;
}

function generateNewApiKey(PDO $pdo, array $config, string $email): void {
    $prefix = getDbPrefix($config);
    $maxKeys = getMaxKeysPerUser($config);
    
    // Check limit
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$prefix}api_keys WHERE email = ?");
    $stmt->execute([$email]);
    $result = $stmt->fetch();
    
    if ($result['count'] >= $maxKeys) {
        throw new Exception("Maximum of {$maxKeys} API keys reached");
    }
    
    $apiKey = generateApiKey();
    
    $stmt = $pdo->prepare("INSERT INTO {$prefix}api_keys (api_key, email) VALUES (?, ?)");
    $stmt->execute([$apiKey, $email]);
}

function deleteApiKey(PDO $pdo, array $config, string $email, int $apiKeyId): void {
    $prefix = getDbPrefix($config);
    // Verify ownership
    $stmt = $pdo->prepare("DELETE FROM {$prefix}api_keys WHERE id = ? AND email = ?");
    $stmt->execute([$apiKeyId, $email]);
}

function renameApiKey(PDO $pdo, array $config, string $email, int $apiKeyId, string $newName): void {
    $prefix = getDbPrefix($config);
    $newName = substr(trim($newName), 0, 64); // Limit length
    $stmt = $pdo->prepare("UPDATE {$prefix}api_keys SET name = ? WHERE id = ? AND email = ?");
    $stmt->execute([$newName, $apiKeyId, $email]);
}

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

/**
 * Get storage statistics for a user.
 * Returns both payload bytes (actual data) and disk bytes (MySQL table usage).
 */
function getStorageStats(PDO $pdo, array $config, string $email): array {
    $prefix = getDbPrefix($config);
    $result = ['payload_bytes' => 0, 'disk_bytes' => 0];
    
    // Get payload bytes from pre-computed stats
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(stats.total_kv_bytes), 0) as kv_bytes,
                COALESCE(SUM(stats.total_event_bytes), 0) as event_bytes,
                COALESCE(SUM(stats.total_events), 0) as user_events
            FROM {$prefix}api_key_stats stats
            JOIN {$prefix}api_keys k ON stats.api_key_id = k.id
            WHERE k.email = ?
        ");
        $stmt->execute([$email]);
        $stats = $stmt->fetch();
        
        $kvBytes = (int)($stats['kv_bytes'] ?? 0);
        $eventPayloadBytes = (int)($stats['event_bytes'] ?? 0);
        $userEvents = (int)($stats['user_events'] ?? 0);
        
        $result['payload_bytes'] = $kvBytes + $eventPayloadBytes;
        
        // Calculate proportional disk usage
        if ($userEvents > 0) {
            $stmt = $pdo->query("
                SELECT 
                    TABLE_ROWS as total_events,
                    DATA_LENGTH + INDEX_LENGTH as table_size
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}events'
            ");
            $tableInfo = $stmt->fetch();
            
            if ($tableInfo && (int)$tableInfo['total_events'] > 0) {
                $proportion = $userEvents / (int)$tableInfo['total_events'];
                $eventDiskBytes = (int)round((int)$tableInfo['table_size'] * $proportion);
                $result['disk_bytes'] = $kvBytes + $eventDiskBytes;
            } else {
                // Fallback: use payload bytes
                $result['disk_bytes'] = $result['payload_bytes'];
            }
        } else {
            $result['disk_bytes'] = $kvBytes;
        }
        
        return $result;
    } catch (Exception $e) {
        // Fall back to KV storage only
    }
    
    // Final fallback: Get KV storage directly
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(LENGTH(kv.value)), 0) as total_kv_storage
            FROM {$prefix}kv_store kv
            JOIN {$prefix}api_keys k ON kv.api_key_id = k.id
            WHERE k.email = ?
        ");
        $stmt->execute([$email]);
        $kvBytes = (int)$stmt->fetch()['total_kv_storage'];
        $result['payload_bytes'] = $kvBytes;
        $result['disk_bytes'] = $kvBytes;
    } catch (Exception $e) {
        // Ignore
    }
    
    return $result;
}

function getUsageStats(PDO $pdo, array $config, string $email, ?int $filterApiKeyId = null): array {
    $prefix = getDbPrefix($config);
    
    // Use pre-computed stats tables (O(days) instead of O(logs))
    try {
        $filterSql = "";
        $params = [$email];
        
        if ($filterApiKeyId) {
            $filterSql = "AND k.id = ?";
            $params[] = $filterApiKeyId;
        }
        
        // Get daily stats from pre-computed table
        $stmt = $pdo->prepare("
            SELECT 
                s.stat_date as date,
                s.total_requests,
                s.success_requests,
                s.error_requests
            FROM {$prefix}api_logs_stats s
            JOIN {$prefix}api_keys k ON s.api_key_id = k.id
            WHERE k.email = ? 
            {$filterSql}
            AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY s.stat_date ASC
        ");
        $stmt->execute($params);
        $dailyStats = $stmt->fetchAll();
        
        // Get endpoint usage from pre-computed table
        $stmt = $pdo->prepare("
            SELECT e.endpoint, e.request_count as count
            FROM {$prefix}api_logs_endpoint_stats e
            JOIN {$prefix}api_keys k ON e.api_key_id = k.id
            WHERE k.email = ? 
            {$filterSql}
            ORDER BY e.request_count DESC
            LIMIT 5
        ");
        $stmt->execute($params);
        $endpointStats = $stmt->fetchAll();
        
        // Get total requests from pre-computed stats
        if ($filterApiKeyId) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total_requests), 0) as total
                FROM {$prefix}api_logs_stats
                WHERE api_key_id = ?
            ");
            $stmt->execute([$filterApiKeyId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(s.total_requests), 0) as total
                FROM {$prefix}api_logs_stats s
                JOIN {$prefix}api_keys k ON s.api_key_id = k.id
                WHERE k.email = ?
            ");
            $stmt->execute([$email]);
        }
        $totalRequests = $stmt->fetch()['total'];
        
        return [
            'daily' => $dailyStats,
            'endpoints' => $endpointStats,
            'total_requests' => $totalRequests
        ];
    } catch (Exception $e) {
        // Fallback to direct queries if stats tables don't exist
        $filterSql = "";
        $params = [$email];
        
        if ($filterApiKeyId) {
            $filterSql = "AND k.id = ?";
            $params[] = $filterApiKeyId;
        }
        
        // Get usage for last 30 days
        $stmt = $pdo->prepare("
            SELECT 
                DATE(l.created_at) as date,
                COUNT(*) as total_requests,
                SUM(CASE WHEN l.status_code >= 200 AND l.status_code < 300 THEN 1 ELSE 0 END) as success_requests,
                SUM(CASE WHEN l.status_code >= 400 THEN 1 ELSE 0 END) as error_requests
            FROM {$prefix}api_logs l
            JOIN {$prefix}api_keys k ON l.api_key_id = k.id
            WHERE k.email = ? 
            {$filterSql}
            AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(l.created_at)
            ORDER BY DATE(l.created_at) ASC
        ");
        $stmt->execute($params);
        $dailyStats = $stmt->fetchAll();
        
        // Get endpoint usage
        $stmt = $pdo->prepare("
            SELECT l.endpoint, COUNT(*) as count
            FROM {$prefix}api_logs l
            JOIN {$prefix}api_keys k ON l.api_key_id = k.id
            WHERE k.email = ? 
            {$filterSql}
            AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY l.endpoint
            ORDER BY count DESC
            LIMIT 5
        ");
        $stmt->execute($params);
        $endpointStats = $stmt->fetchAll();

        // Get total requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM {$prefix}api_logs l
            JOIN {$prefix}api_keys k ON l.api_key_id = k.id
            WHERE k.email = ?
            {$filterSql}
        ");
        $stmt->execute($params);
        $totalRequests = $stmt->fetch()['total'];

        return [
            'daily' => $dailyStats,
            'endpoints' => $endpointStats,
            'total_requests' => $totalRequests
        ];
    }
}

// --- KV Store Functions ---
function getAllKeyValuePairs(PDO $pdo, array $config, string $email, int $limit = 500, int $offset = 0): array {
    $prefix = getDbPrefix($config);
    
    // Add pagination to prevent loading too many items at once
    $stmt = $pdo->prepare("
        SELECT 
            kv.id,
            kv.key,
            kv.value,
            kv.created_at,
            kv.updated_at,
            ak.api_key,
            ak.id as api_key_id,
            ak.name as api_key_name
        FROM {$prefix}kv_store kv
        JOIN {$prefix}api_keys ak ON kv.api_key_id = ak.id
        WHERE ak.email = ?
        ORDER BY kv.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$email, $limit, $offset]);
    return $stmt->fetchAll();
}

function getKeyValueStats(PDO $pdo, array $config, string $email): array {
    $prefix = getDbPrefix($config);
    
    // Use pre-computed stats from api_key_stats table (O(keys) instead of O(kv_pairs))
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(stats.total_kv_pairs), 0) as total_keys,
                COUNT(DISTINCT CASE WHEN stats.total_kv_pairs > 0 THEN stats.api_key_id END) as api_keys_with_data,
                COALESCE(SUM(stats.total_kv_bytes), 0) as total_storage_bytes
            FROM {$prefix}api_key_stats stats
            JOIN {$prefix}api_keys ak ON stats.api_key_id = ak.id
            WHERE ak.email = ?
        ");
        $stmt->execute([$email]);
        return $stmt->fetch();
    } catch (Exception $e) {
        // Fallback to direct query if stats table doesn't exist
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_keys,
                COUNT(DISTINCT kv.api_key_id) as api_keys_with_data,
                SUM(LENGTH(kv.value)) as total_storage_bytes
            FROM {$prefix}kv_store kv
            JOIN {$prefix}api_keys ak ON kv.api_key_id = ak.id
            WHERE ak.email = ?
        ");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
}

function truncateValue(string $value, int $maxLength = 100): string {
    if (strlen($value) <= $maxLength) {
        return $value;
    }
    return substr($value, 0, $maxLength) . '...';
}

function truncateKey(string $key, int $maxLength = 40): string {
    if (strlen($key) <= $maxLength) {
        return $key;
    }
    return substr($key, 0, 20) . '...' . substr($key, -10);
}

// --- Event Explorer Functions ---
function getEventStats(PDO $pdo, array $config, string $email, ?int $filterApiKeyId = null): array {
    $prefix = getDbPrefix($config);
    
    $total = 0;
    $today = 0;
    $activeSources = 0;
    
    // Use pre-computed stats for total (O(1) instead of O(31M))
    try {
        if ($filterApiKeyId) {
            // Single API key - use stats table
            $stmt = $pdo->prepare("
                SELECT COALESCE(stats.total_events, 0) as total
                FROM {$prefix}api_key_stats stats
                WHERE stats.api_key_id = ?
            ");
            $stmt->execute([$filterApiKeyId]);
            $result = $stmt->fetch();
            $total = $result ? (int)$result['total'] : 0;
        } else {
            // All API keys for user - sum from stats table
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(stats.total_events), 0) as total
                FROM {$prefix}api_key_stats stats
                JOIN {$prefix}api_keys k ON stats.api_key_id = k.id
                WHERE k.email = ?
            ");
            $stmt->execute([$email]);
            $result = $stmt->fetch();
            $total = $result ? (int)$result['total'] : 0;
        }
    } catch (Exception $e) {
        // Stats table doesn't exist - return 0 to avoid timeout
        $total = 0;
    }
    
    // Events today - use event_stats table (O(keys) not O(events))
    try {
        if ($filterApiKeyId) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(event_count, 0) as today
                FROM {$prefix}event_stats
                WHERE api_key_id = ? AND stat_date = CURDATE()
            ");
            $stmt->execute([$filterApiKeyId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(es.event_count), 0) as today
                FROM {$prefix}event_stats es
                JOIN {$prefix}api_keys k ON es.api_key_id = k.id
                WHERE k.email = ? AND es.stat_date = CURDATE()
            ");
            $stmt->execute([$email]);
        }
        $result = $stmt->fetch();
        $today = $result ? (int)$result['today'] : 0;
    } catch (Exception $e) {
        $today = 0;
    }
    
    // Active Sources - use event_stats table (O(keys * 7 days) not O(events))
    try {
        if ($filterApiKeyId) {
            // Check if this API key has any events in last 7 days
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as has_events
                FROM {$prefix}event_stats
                WHERE api_key_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND event_count > 0
            ");
            $stmt->execute([$filterApiKeyId]);
            $activeSources = $stmt->fetch()['has_events'] > 0 ? 1 : 0;
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.api_key_id) as active_sources
                FROM {$prefix}event_stats es
                JOIN {$prefix}api_keys k ON es.api_key_id = k.id
                WHERE k.email = ? AND es.stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND es.event_count > 0
            ");
            $stmt->execute([$email]);
            $activeSources = (int)$stmt->fetch()['active_sources'];
        }
    } catch (Exception $e) {
        $activeSources = 0;
    }
    
    return [
        'total' => $total,
        'today' => $today,
        'active_sources' => $activeSources
    ];
}

function getEventVolumeHistory(PDO $pdo, array $config, string $email, ?int $filterApiKeyId = null): array {
    $prefix = getDbPrefix($config);
    
    // Use pre-computed event_stats table (O(30 days) instead of O(31M events))
    try {
        if ($filterApiKeyId) {
            $stmt = $pdo->prepare("
                SELECT stat_date as date, event_count as count
                FROM {$prefix}event_stats
                WHERE api_key_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY stat_date ASC
            ");
            $stmt->execute([$filterApiKeyId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT es.stat_date as date, SUM(es.event_count) as count
                FROM {$prefix}event_stats es
                JOIN {$prefix}api_keys k ON es.api_key_id = k.id
                WHERE k.email = ? AND es.stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY es.stat_date
                ORDER BY es.stat_date ASC
            ");
            $stmt->execute([$email]);
        }
        return $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback if stats table doesn't exist - return empty to avoid timeout
        return [];
    }
}

function getCommonEventKeys(PDO $pdo, array $config, string $email, ?int $filterApiKeyId = null): array {
    $prefix = getDbPrefix($config);
    
    // Get API key if not provided
    if (!$filterApiKeyId) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM {$prefix}api_keys WHERE email = ? ORDER BY last_used_at DESC LIMIT 1");
            $stmt->execute([$email]);
            $result = $stmt->fetch();
            if ($result) {
                $filterApiKeyId = (int)$result['id'];
            } else {
                return [];
            }
        } catch (Exception $e) {
            return [];
        }
    }
    
    // Use pre-computed event_key_stats table (O(1) instead of scanning events)
    try {
        $stmt = $pdo->prepare("
            SELECT event_key, occurrence_count
            FROM {$prefix}event_key_stats
            WHERE api_key_id = ?
            ORDER BY occurrence_count DESC
            LIMIT 10
        ");
        $stmt->execute([$filterApiKeyId]);
        $rows = $stmt->fetchAll();
        
        if (empty($rows)) {
            return [];
        }
        
        // Calculate total occurrences for percentage
        $total = array_sum(array_column($rows, 'occurrence_count'));
        
        $result = [];
        foreach ($rows as $row) {
            $percentage = $total > 0 ? round(($row['occurrence_count'] / $total) * 100) : 0;
            $result[$row['event_key']] = $percentage;
        }
        
        return $result;
    } catch (Exception $e) {
        // Fallback to sampling recent events if stats table doesn't exist
        try {
            $stmt = $pdo->prepare("
                SELECT e.event_data
                FROM {$prefix}events e
                WHERE e.api_key_id = ?
                ORDER BY e.id DESC
                LIMIT 50
            ");
            $stmt->execute([$filterApiKeyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $keyCounts = [];
            $total = count($rows);
            
            if ($total === 0) return [];

            foreach ($rows as $json) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    foreach (array_keys($data) as $key) {
                        $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
                    }
                }
            }
            
            arsort($keyCounts);
            
            $result = [];
            foreach (array_slice($keyCounts, 0, 10) as $key => $count) {
                $result[$key] = round(($count / $total) * 100);
            }
            
            return $result;
        } catch (Exception $e) {
            return [];
        }
    }
}

function getRecentEvents(PDO $pdo, array $config, string $email, int $limit, int $offset, string $search = '', ?int $filterApiKeyId = null, ?int $beforeId = null): array {
    $prefix = getDbPrefix($config);
    
    // Get default API key if none provided
    if (!$filterApiKeyId && empty($search)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM {$prefix}api_keys WHERE email = ? ORDER BY last_used_at DESC LIMIT 1");
            $stmt->execute([$email]);
            $result = $stmt->fetch();
            if ($result) {
                $filterApiKeyId = (int)$result['id'];
            } else {
                return []; // No API keys = no events
            }
        } catch (Exception $e) {
            return [];
        }
    }
    
    // Check event count - if too high, skip offset to avoid timeout
    try {
        $stmt = $pdo->prepare("SELECT total_events FROM {$prefix}api_key_stats WHERE api_key_id = ?");
        $stmt->execute([$filterApiKeyId]);
        $stats = $stmt->fetch();
        // If > 10M events and offset > 0, skip
        if ($stats && (int)$stats['total_events'] > 10000000 && $offset > 0) {
            return [];
        }
    } catch (Exception $e) {
        // Stats table might not exist, continue
    }
    
    // Fast path: single API key
    if ($filterApiKeyId && empty($search)) {
        $params = [$filterApiKeyId];
        $where = "WHERE e.api_key_id = ?";
        
        if ($beforeId !== null) {
            $where .= " AND e.id < ?";
            $params[] = $beforeId;
        }
        
        $sql = "
            SELECT 
                e.id,
                e.event_data,
                e.event_timestamp,
                k.name as key_name,
                k.api_key
            FROM {$prefix}events e
            JOIN {$prefix}api_keys k ON e.api_key_id = k.id
            $where
            ORDER BY e.id DESC
            LIMIT ?
        ";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    // Search queries - MUST have filterApiKeyId to be fast
    if (!empty($search) && !$filterApiKeyId) {
        return [];
    }
    
    // Search with API key filter
    $where = "WHERE e.api_key_id = ?";
    $params = [$filterApiKeyId];
    
    if (!empty($search)) {
        $where .= " AND (e.event_data LIKE ? OR k.name LIKE ? OR k.api_key LIKE ?)";
        $term = "%$search%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
    
    // Limit offset to prevent timeout on deep pagination
    $safeOffset = min($offset, 1000);
    
    $sql = "
        SELECT 
            e.id,
            e.event_data,
            e.event_timestamp,
            k.name as key_name,
            k.api_key
        FROM {$prefix}events e
        JOIN {$prefix}api_keys k ON e.api_key_id = k.id
        $where
        ORDER BY e.id DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $safeOffset, PDO::PARAM_INT);
    
    $stmt->execute();
    return $stmt->fetchAll();
}

function formatMicrotime($timestamp) {
    $parts = explode('.', $timestamp);
    return isset($parts[1]) ? $parts[1] : '000';
}

// --- Request Logs Functions ---
function getApiLogs(PDO $pdo, array $config, string $email, int $limit = 100, int $offset = 0, array $filters = []): array {
    $prefix = getDbPrefix($config);
    
    $where = "WHERE k.email = ?";
    $params = [$email];
    
    // Default to last 30 days if no date filter
    $hasDateFilter = !empty($filters['date_from']) || !empty($filters['date_to']);
    if (!$hasDateFilter) {
        $where .= " AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    // Filter by API key
    if (!empty($filters['api_key_id'])) {
        $where .= " AND k.id = ?";
        $params[] = (int)$filters['api_key_id'];
    }
    
    // Filter by status code category
    if (!empty($filters['status_filter'])) {
        switch ($filters['status_filter']) {
            case 'success': // 2xx
                $where .= " AND l.status_code >= 200 AND l.status_code < 300";
                break;
            case 'redirect': // 3xx
                $where .= " AND l.status_code >= 300 AND l.status_code < 400";
                break;
            case 'client_error': // 4xx
                $where .= " AND l.status_code >= 400 AND l.status_code < 500";
                break;
            case 'server_error': // 5xx
                $where .= " AND l.status_code >= 500";
                break;
            case 'errors': // 4xx + 5xx
                $where .= " AND l.status_code >= 400";
                break;
        }
    }
    
    // Filter by endpoint
    if (!empty($filters['endpoint'])) {
        $where .= " AND l.endpoint = ?";
        $params[] = $filters['endpoint'];
    }
    
    // Filter by method
    if (!empty($filters['method'])) {
        $where .= " AND l.method = ?";
        $params[] = strtoupper($filters['method']);
    }
    
    // Filter by date range
    if (!empty($filters['date_from'])) {
        $where .= " AND l.created_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where .= " AND l.created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    
    // Limit offset to prevent slow deep pagination
    $safeOffset = min($offset, 10000);
    
    $sql = "
        SELECT 
            l.id,
            l.endpoint,
            l.method,
            l.status_code,
            l.created_at,
            k.id as api_key_id,
            k.api_key,
            k.name as api_key_name
        FROM {$prefix}api_logs l
        JOIN {$prefix}api_keys k ON l.api_key_id = k.id
        $where
        ORDER BY l.id DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $safeOffset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll();
}

function getApiLogsCount(PDO $pdo, array $config, string $email, array $filters = []): int {
    $prefix = getDbPrefix($config);
    
    // If no complex filters, use pre-computed stats (much faster)
    $hasComplexFilters = !empty($filters['status_filter']) || !empty($filters['endpoint']) || !empty($filters['method']);
    $hasDateFilter = !empty($filters['date_from']) || !empty($filters['date_to']);
    
    if (!$hasComplexFilters && !$hasDateFilter) {
        // Fast path: use pre-computed stats for last 30 days
        try {
            if (!empty($filters['api_key_id'])) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(total_requests), 0) as total
                    FROM {$prefix}api_logs_stats
                    WHERE api_key_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmt->execute([(int)$filters['api_key_id']]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(s.total_requests), 0) as total
                    FROM {$prefix}api_logs_stats s
                    JOIN {$prefix}api_keys k ON s.api_key_id = k.id
                    WHERE k.email = ? AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmt->execute([$email]);
            }
            return (int)$stmt->fetch()['total'];
        } catch (Exception $e) {
            // Stats table doesn't exist, fall through to direct query
        }
    }
    
    // Fallback: direct query with filters
    $where = "WHERE k.email = ?";
    $params = [$email];
    
    // Add default date filter if none specified
    if (!$hasDateFilter) {
        $where .= " AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    if (!empty($filters['api_key_id'])) {
        $where .= " AND k.id = ?";
        $params[] = (int)$filters['api_key_id'];
    }
    
    if (!empty($filters['status_filter'])) {
        switch ($filters['status_filter']) {
            case 'success':
                $where .= " AND l.status_code >= 200 AND l.status_code < 300";
                break;
            case 'redirect':
                $where .= " AND l.status_code >= 300 AND l.status_code < 400";
                break;
            case 'client_error':
                $where .= " AND l.status_code >= 400 AND l.status_code < 500";
                break;
            case 'server_error':
                $where .= " AND l.status_code >= 500";
                break;
            case 'errors':
                $where .= " AND l.status_code >= 400";
                break;
        }
    }
    
    if (!empty($filters['endpoint'])) {
        $where .= " AND l.endpoint = ?";
        $params[] = $filters['endpoint'];
    }
    
    if (!empty($filters['method'])) {
        $where .= " AND l.method = ?";
        $params[] = strtoupper($filters['method']);
    }
    
    if (!empty($filters['date_from'])) {
        $where .= " AND l.created_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where .= " AND l.created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM {$prefix}api_logs l
        JOIN {$prefix}api_keys k ON l.api_key_id = k.id
        $where
    ");
    $stmt->execute($params);
    return (int)$stmt->fetch()['total'];
}

function getUniqueEndpoints(PDO $pdo, array $config, string $email): array {
    $prefix = getDbPrefix($config);
    
    // Use pre-computed endpoint stats table (much faster than DISTINCT on logs)
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.endpoint
            FROM {$prefix}api_logs_endpoint_stats e
            JOIN {$prefix}api_keys k ON e.api_key_id = k.id
            WHERE k.email = ?
            ORDER BY e.endpoint ASC
        ");
        $stmt->execute([$email]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Fallback to direct query if stats table doesn't exist
        $stmt = $pdo->prepare("
            SELECT DISTINCT l.endpoint
            FROM {$prefix}api_logs l
            JOIN {$prefix}api_keys k ON l.api_key_id = k.id
            WHERE k.email = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY l.endpoint ASC
        ");
        $stmt->execute([$email]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

function getLogStatusStats(PDO $pdo, array $config, string $email, ?int $apiKeyId = null): array {
    $prefix = getDbPrefix($config);
    
    // Use pre-computed stats table for much faster results
    try {
        if ($apiKeyId) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(total_requests), 0) as total,
                    COALESCE(SUM(success_requests), 0) as success,
                    COALESCE(SUM(error_requests), 0) as errors
                FROM {$prefix}api_logs_stats
                WHERE api_key_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$apiKeyId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(s.total_requests), 0) as total,
                    COALESCE(SUM(s.success_requests), 0) as success,
                    COALESCE(SUM(s.error_requests), 0) as errors
                FROM {$prefix}api_logs_stats s
                JOIN {$prefix}api_keys k ON s.api_key_id = k.id
                WHERE k.email = ? AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$email]);
        }
        
        $result = $stmt->fetch();
        return [
            'total' => (int)$result['total'],
            'success' => (int)$result['success'],
            'errors' => (int)$result['errors']
        ];
    } catch (Exception $e) {
        // Fallback to direct query if stats table doesn't exist
    }
    
    // Fallback: Only count last 30 days of logs to avoid scanning entire history
    $where = "WHERE k.email = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $params = [$email];
    
    if ($apiKeyId) {
        $where .= " AND k.id = ?";
        $params[] = $apiKeyId;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN l.status_code >= 200 AND l.status_code < 300 THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN l.status_code >= 400 AND l.status_code < 500 THEN 1 ELSE 0 END) as client_error_count,
            SUM(CASE WHEN l.status_code >= 500 THEN 1 ELSE 0 END) as server_error_count,
            COUNT(*) as total_count
        FROM {$prefix}api_logs l
        JOIN {$prefix}api_keys k ON l.api_key_id = k.id
        $where
    ");
    $stmt->execute($params);
    return $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Dashboard | API Keys, Usage & Data</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="icon" href="<?= htmlspecialchars(getBasePath()) ?>/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= htmlspecialchars(getBasePath()) ?>/css/style.css">
    <style>
        .section { display: none; }
        .section.active { display: block; }
        
        /* Event Explorer Styles */
        .json-preview {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: var(--text-secondary);
            max-width: 400px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .timestamp-col {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        
        .source-badge {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            background: rgba(59, 130, 246, 0.1);
            color: #60a5fa;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .key-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 9999px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            transition: var(--transition);
            cursor: pointer;
        }
        
        .key-badge:hover {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
            color: var(--text-primary);
        }
        
        .key-badge .count {
            color: var(--text-muted);
            font-size: 0.7rem;
        }
        
        /* JSON Syntax Highlighting */
        .string { color: #a5b4fc; }
        .number { color: #6ee7b7; }
        .boolean { color: #fca5a5; }
        .null { color: #9ca3af; }
        .key { color: #94a3b8; font-weight: 600; }
        
        .response-block {
            margin-top: 0.5rem;
            border-top: 1px solid var(--border-color);
            background: rgba(0, 0, 0, 0.2);
            padding: 1rem;
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .response-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            color: #6b7280;
            font-weight: 600;
        }
        .code-block {
            margin-bottom: 0;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            position: relative;
            white-space: normal; /* Fix for extra whitespace rendering */
        }
        .code-block > div {
            white-space: pre-wrap;
            word-break: break-all;
            font-family: var(--font-mono);
            padding-right: 24px;
        }
        .copy-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            padding: 0;
        }
        .copy-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            color: var(--text-primary);
        }
        .code-block .copy-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
        }
        
        /* API Playground Styles */
        .playground-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 1200px) {
            .playground-container {
                grid-template-columns: 1fr;
            }
        }
        
        .playground-request,
        .playground-response {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .playground-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .playground-row {
                flex-direction: column;
            }
            .playground-row .form-group {
                flex: 1 1 100% !important;
            }
        }
        
        .playground-select {
            font-family: var(--font-mono);
            font-size: 0.875rem;
        }
        
        .playground-endpoint-wrapper {
            display: flex;
            align-items: center;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }
        
        .playground-endpoint-wrapper:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        
        .playground-method {
            padding: 0.75rem 1rem;
            font-weight: 700;
            font-size: 0.75rem;
            font-family: var(--font-mono);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            min-width: 70px;
            text-align: center;
            transition: all 0.2s;
        }
        
        .playground-method.method-get {
            background: #065f46;
            color: #6ee7b7;
        }
        
        .playground-method.method-post {
            background: #1e40af;
            color: #93c5fd;
        }
        
        .playground-method.method-delete {
            background: #991b1b;
            color: #fca5a5;
        }
        
        .playground-endpoint-select {
            flex: 1;
            border: none;
            background: transparent;
            padding-left: 1rem;
        }
        
        .playground-endpoint-select:focus {
            outline: none;
            box-shadow: none;
        }
        
        .playground-textarea {
            font-family: var(--font-mono);
            font-size: 0.875rem;
            line-height: 1.6;
            resize: vertical;
            min-height: 140px;
        }
        
        .playground-error {
            color: var(--danger);
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: none;
        }
        
        .playground-error.visible {
            display: block;
        }
        
        .playground-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .playground-send-btn {
            min-width: 150px;
        }
        
        .playground-send-btn.loading {
            opacity: 0.7;
            pointer-events: none;
        }
        
        .playground-send-btn.loading .btn-icon-send {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .playground-response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .playground-response-meta {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .playground-status {
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            background: rgba(148, 163, 184, 0.1);
            color: var(--text-muted);
        }
        
        .playground-status.status-success {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        
        .playground-status.status-error {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }
        
        .playground-status.status-redirect {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
        }
        
        .playground-time {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .playground-response-tabs {
            display: flex;
            gap: 0.25rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }
        
        .playground-tab {
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }
        
        .playground-tab:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
        }
        
        .playground-tab.active {
            color: var(--primary);
            background: rgba(59, 130, 246, 0.1);
        }
        
        .playground-response-body {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            min-height: 250px;
            max-height: 400px;
            overflow: auto;
            position: relative;
        }
        
        .playground-response-content {
            display: none;
            padding: 1rem;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .playground-response-content.active {
            display: block;
        }
        
        .playground-empty-response {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            color: var(--text-muted);
            text-align: center;
        }
        
        .playground-empty-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        
        .playground-response-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            padding-top: 0.5rem;
        }
        
        .playground-tips {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        
        .playground-tip {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: rgba(59, 130, 246, 0.05);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .playground-tip-icon {
            font-size: 1.1rem;
        }
        
        .playground-tip code {
            background: rgba(0, 0, 0, 0.3);
            padding: 0.1rem 0.4rem;
            border-radius: 3px;
            font-size: 0.8rem;
            color: #a5b4fc;
        }
        
        /* JSON Syntax Highlighting in Response */
        .json-key { color: #93c5fd; }
        .json-string { color: #86efac; }
        .json-number { color: #fbbf24; }
        .json-boolean { color: #f472b6; }
        .json-null { color: #94a3b8; }
        
        /* Header display in response */
        .response-header-row {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 0;
        }
        
        .response-header-row:last-child {
            border-bottom: none;
        }
        
        .response-header-name {
            min-width: 200px;
            color: #93c5fd;
            font-weight: 500;
        }
        
        .response-header-value {
            color: var(--text-secondary);
            word-break: break-all;
        }
        
        /* Request Logs Styles */
        .method-badge {
            font-family: var(--font-mono);
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        
        .method-badge.method-get {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .method-badge.method-post {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .method-badge.method-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .method-badge.method-put {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .method-badge.method-patch {
            background: rgba(139, 92, 246, 0.15);
            color: #a78bfa;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        .endpoint-badge {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: #94a3b8;
            background: rgba(0, 0, 0, 0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        
        .log-row {
            transition: background 0.15s ease;
        }
        
        .log-row:hover {
            background: rgba(59, 130, 246, 0.05) !important;
        }
        
        .logs-filters select,
        .logs-filters input {
            font-size: 0.8rem;
        }
        
        .logs-pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Make date inputs match our style */
        input[type="date"] {
            color-scheme: dark;
        }
        
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(0.7);
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .logs-filters {
                flex-direction: column;
            }
            
            .logs-filters .form-group {
                width: 100%;
                min-width: 100% !important;
            }
            
            .logs-pagination {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
            }
            
            .logs-pagination .flex {
                justify-content: center;
            }
            
            /* Playground responsive */
            .playground-container {
                grid-template-columns: 1fr !important;
                gap: 1.5rem;
            }
            
            .playground-row {
                flex-direction: column;
            }
            
            .playground-row .form-group {
                flex: 1 1 100% !important;
            }
            
            .playground-endpoint-wrapper {
                flex-direction: column;
                align-items: stretch;
            }
            
            .playground-method {
                width: 100%;
                border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            }
            
            .playground-endpoint-select {
                padding: 0.75rem !important;
            }
            
            .playground-actions {
                flex-direction: column;
            }
            
            .playground-actions .btn {
                width: 100%;
            }
            
            .playground-response-meta {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            
            .playground-response-body {
                min-height: 200px;
                max-height: 300px;
            }
            
            .playground-tips {
                margin-top: 1rem;
                padding-top: 1rem;
            }
            
            .playground-tip {
                font-size: 0.8rem;
                padding: 0.5rem 0.75rem;
            }
            
            /* Events section mobile */
            .key-badge {
                padding: 0.2rem 0.5rem;
                font-size: 0.7rem;
            }
            
            #events .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            #events .card-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            #events .card-header > div {
                width: 100%;
            }
            
            #event-volume-chart {
                height: 200px !important;
            }
            
            /* Data section mobile */
            #viewdata .card-header .flex {
                flex-wrap: wrap;
            }
            
            #kvSearchInput {
                order: -1;
                width: 100% !important;
            }
            
            /* Charts mobile */
            .charts-container {
                grid-template-columns: 1fr !important;
                gap: 1.5rem;
            }
            
            #requests-chart,
            #endpoints-chart {
                height: 200px !important;
            }
            
            /* Pagination mobile */
            .logs-pagination div:first-child {
                font-size: 0.75rem;
            }
            
            /* Code blocks in docs */
            .code-block > div {
                font-size: 0.7rem !important;
                padding-right: 35px;
            }
            
            /* API Reference responsive */
            #documentation .flex.items-center.gap-2 {
                flex-wrap: wrap;
            }
            
            #documentation .code-block {
                font-size: 0.7rem;
            }
            
            .response-block pre {
                font-size: 0.7rem;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 480px) {
            #events .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .playground-response-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .playground-tab {
                flex-shrink: 0;
            }
            
            .json-preview {
                max-width: 150px !important;
                font-size: 0.7rem;
            }
            
            .timestamp-col {
                font-size: 0.75rem;
            }
            
            .source-badge {
                font-size: 0.65rem;
                padding: 2px 4px;
            }
            
            .method-badge {
                font-size: 0.6rem;
                padding: 0.15rem 0.35rem;
            }
            
            .endpoint-badge {
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container header-content">
            <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/" class="logo">
                <span class="logo-icon">🚀</span>
                <span class="hidden-mobile">Dashboard</span>
            </a>
            <div class="flex items-center gap-2">
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
                <?php if ($isSuperadmin): ?>
                    <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/superadmin.php" class="btn btn-primary btn-sm badge-superadmin" title="Superadmin">
                        🛡️ Superadmin
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(getBasePath()) ?>/admin/logout.php" class="btn btn-ghost btn-sm" title="Logout">
                    Logout
                </a>
            </div>
        </div>
    </header>
    
    <main class="container animate-fade-in" style="padding-top: 2rem;">
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger">
                ⚠️ <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total API Keys</div>
                <div class="stat-value" id="stat-api-keys"><?= $apiKeyCount ?> <span style="font-size: 1rem; color: var(--text-muted); font-weight: 400;">/ <?= $maxKeys ?></span></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Stored Keys</div>
                <div class="stat-value" id="stat-stored-keys"><?= number_format(array_sum(array_column($apiKeys, 'key_count'))) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Requests</div>
                <div class="stat-value" id="stat-total-requests"><?= number_format($usageStats['total_requests'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Data Points</div>
                <div class="stat-value" id="stat-event-total-top"><?= number_format($eventStats['total'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Payload Data</div>
                <div class="stat-value" id="stat-payload"><?= formatBytes($totalPayloadBytes) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Storage</div>
                <div class="stat-value" id="stat-total-storage"><?= formatBytes($totalStorageBytes) ?></div>
            </div>
        </div>

        <!-- Tabs with Global API Key Filter -->
        <div class="tabs-container" style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
            <div class="tabs" style="margin-bottom: 0;">
                <?php if ($hasAnyData): ?>
                <button class="tab-btn active" onclick="showSection('insights')">📊 <span class="hidden-mobile">Insights</span></button>
                <?php endif; ?>
                <button class="tab-btn<?= !$hasAnyData ? ' active' : '' ?>" onclick="showSection('apikeys')">🔑 <span class="hidden-mobile">API </span>Keys</button>
                <button class="tab-btn" onclick="showSection('viewdata')">💾 Data</button>
                <button class="tab-btn" onclick="showSection('events')">📡 Events</button>
                <button class="tab-btn" onclick="showSection('schemas')">⚡ Schema</button>
                <button class="tab-btn" onclick="showSection('logs')">📋 Logs</button>
                <button class="tab-btn" onclick="showSection('documentation')">📖 <span class="hidden-mobile">API </span>Docs</button>
                <button class="tab-btn" onclick="showSection('playground')">🧪 <span class="hidden-mobile">Play</span></button>
            </div>
            
            <?php if (count($apiKeys) > 1): ?>
            <div class="global-filter" style="display: flex; align-items: center; gap: 0.5rem;">
                <label for="globalApiKeyFilter" class="text-sm font-medium text-muted hidden-mobile" style="white-space: nowrap;">Filter by key:</label>
                <select id="globalApiKeyFilter" class="form-control" style="width: auto; flex: 1; max-width: 220px; min-width: 140px; padding: 0.4rem 2rem 0.4rem 0.75rem; font-size: 0.8rem;">
                    <option value="">All API Keys</option>
                    <?php foreach ($apiKeys as $key): ?>
                        <option value="<?= $key['id'] ?>" data-key="<?= htmlspecialchars($key['api_key']) ?>">
                            <?= htmlspecialchars($key['name'] ?: substr($key['api_key'], 0, 12) . '...') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <!-- Insights Section -->
        <div id="insights" class="section<?= $hasAnyData ? ' active' : '' ?>"<?= !$hasAnyData ? ' style="display: none;"' : '' ?>>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📊 Usage Statistics</h2>
            </div>
            <div class="card-body">
                <div class="charts-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                    <div>
                        <h3 style="font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: var(--text-muted);">Requests (Last 30 Days)</h3>
                        <div id="requests-chart" style="width: 100%; height: 250px;"></div>
                    </div>
                    <div>
                        <h3 style="font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: var(--text-muted);">Top Endpoints</h3>
                        <div id="endpoints-chart" style="width: 100%; height: 250px;"></div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        
        <!-- API Keys Section -->
        <div id="apikeys" class="section<?= !$hasAnyData ? ' active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🔑 Your API Keys</h2>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                    <input type="hidden" name="action" value="generate">
                    <button type="submit" class="btn btn-primary" <?= !$canGenerateMore ? 'disabled' : '' ?>>
                        <span>+ Generate New Key</span>
                    </button>
                </form>
            </div>
            
            <div class="table-responsive">
                <?php if (empty($apiKeys)): ?>
                    <div class="empty-state">
                        <span class="empty-icon">📝</span>
                        <p>No API keys yet. Generate your first one to get started!</p>
                    </div>
                <?php else: ?>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>API Key</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th>Data</th>
                                <th>Events</th>
                                <th>Payload</th>
                                <th>Storage</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key): ?>
                                <tr>
                                    <td data-label="Name">
                                        <form method="POST" style="display: flex; align-items: center;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                            <input type="hidden" name="action" value="rename">
                                            <input type="hidden" name="api_key_id" value="<?= $key['id'] ?>">
                                            <input type="text" name="name" 
                                                   value="<?= htmlspecialchars($key['name'] ?? '') ?>" 
                                                   placeholder="Untitled Key"
                                                   class="form-control"
                                                   style="padding: 4px 8px; font-size: 0.875rem; width: 100%; min-width: 100px; max-width: 180px; background: transparent; border: 1px solid transparent; color: var(--text-primary);"
                                                   onfocus="this.style.borderColor='var(--primary-color)'; this.style.background='rgba(0,0,0,0.2)';"
                                                   onblur="this.style.borderColor='transparent'; this.style.background='transparent'; if(this.value !== this.defaultValue) this.form.submit();">
                                        </form>
                                    </td>
                                    <td data-label="API Key">
                                        <div class="flex items-center gap-2">
                                            <code class="api-key-display" title="<?= htmlspecialchars($key['api_key']) ?>">
                                                <?= htmlspecialchars(substr($key['api_key'], 0, 12) . '...' . substr($key['api_key'], -4)) ?>
                                            </code>
                                            <button class="copy-btn" onclick="copyToClipboard('<?= $key['api_key'] ?>', this)" title="Copy API Key">📋</button>
                                        </div>
                                    </td>
                                    <td data-label="Created"><?= date('M j, Y', strtotime($key['created_at'])) ?></td>
                                    <td data-label="Last Used">
                                        <?php if ($key['last_used_at']): ?>
                                            <?= date('M j H:i', strtotime($key['last_used_at'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Data">
                                        <span class="badge badge-neutral"><?= $key['key_count'] ?></span>
                                    </td>
                                    <td data-label="Events">
                                        <span class="badge badge-neutral"><?= number_format($key['event_count']) ?></span>
                                    </td>
                                    <td data-label="Payload">
                                        <span class="badge badge-neutral"><?= formatBytes((int)$key['payload_bytes']) ?></span>
                                    </td>
                                    <td data-label="Storage">
                                        <span class="badge badge-neutral"><?= formatBytes((int)$key['total_storage_bytes']) ?></span>
                                    </td>
                                    <td data-label="Actions" class="text-right">
                                        <form method="POST" onsubmit="return confirm('Delete this API key? All associated data will be permanently removed.');" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCSRFToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="api_key_id" value="<?= $key['id'] ?>">
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
        
        <!-- API Reference Section -->
        <div id="documentation" class="section">
        <div class="card" id="api-docs-card">
            <div class="card-header">
                <h2 class="card-title">📖 API Reference</h2>
            </div>
            <div class="card-body" id="api-docs-body">
                <div class="alert alert-success" style="margin-bottom: 2rem;">
                    <strong>Base URL:</strong> <code style="color: inherit;"><?= htmlspecialchars($apiBaseUrl) ?></code><br>
                    <strong>Authentication:</strong> Include your API key in the <code style="color: inherit;">X-API-Key</code> header.
                </div>
                
                <h3 style="margin-bottom: 1.5rem; color: var(--text-primary); font-size: 1.25rem; font-weight: 600;">🗄️ Key/Value Store API</h3>
                
                <div style="display: grid; gap: 2rem;">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/set</code>
                            <span class="text-muted">- Store a value (generates UUID key)</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-set">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/set \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"value": "myvalue"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-set').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Value stored successfully","key":"550e8400-e29b-41d4-a716-446655440000"}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/set</code>
                            <span class="text-muted">- Update existing key (or create if not exists)</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-set-update">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/set \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"key": "550e8400-e29b-41d4-a716-446655440000", "value": "updated value"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-set-update').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Value stored successfully","key":"550e8400-e29b-41d4-a716-446655440000"}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/get</code>
                            <span class="text-muted">- Retrieve a value</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-get">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/get \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"key": "YOUR_KEY_UUID"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-get').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","key":"550e8400-e29b-41d4-a716-446655440000","value":"myvalue"}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/exists</code>
                            <span class="text-muted">- Check existence</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-exists">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/exists \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"key": "YOUR_KEY_UUID"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-exists').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","key":"550e8400-e29b-41d4-a716-446655440000","exists":true}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-success" style="background: #d1fae5; color: #065f46;">GET</span>
                            <code style="color: var(--text-primary);">/api/list</code>
                            <span class="text-muted">- List keys</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-list">curl <?= htmlspecialchars($apiBaseUrl) ?>/list \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>"</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-list').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","count":2,"keys":[{"key":"550e8400...","created_at":"..."},{"key":"...","created_at":"..."}]}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/delete</code>
                            <span class="text-muted">- Delete a key</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-delete">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/delete \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"key": "YOUR_KEY_UUID"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-delete').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Key deleted successfully","key":"550e8400-e29b-41d4-a716-446655440000"}</pre>
                        </div>
                    </div>
                </div>
                
                <hr style="margin: 3rem 0; border: none; border-top: 1px solid var(--border-color);">
                
                <h3 style="margin-bottom: 1.5rem; color: var(--text-primary); font-size: 1.25rem; font-weight: 600;">📡 Event Data API</h3>
                <p style="color: var(--text-muted); margin-bottom: 1rem;">Store and query time-series event data for IoT, analytics, monitoring, and more.</p>
                <div class="alert" style="margin-bottom: 2rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); font-size: 0.875rem;">
                    <strong>💡 Tip:</strong> Events support an optional <code>timestamp</code> field (ISO 8601 format, e.g., <code>"2024-12-04T10:30:00Z"</code>). If omitted, the current server time is used.
                </div>
                
                <div style="display: grid; gap: 2rem;">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/event/push</code>
                            <span class="text-muted">- Push single event (uses current time)</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-push">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/event/push \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"data": {"temperature": 23.5, "humidity": 65}}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-push').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Events stored successfully","count":1}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/event/push</code>
                            <span class="text-muted">- Push event with custom timestamp</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-push-timestamp">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/event/push \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"data": {"temperature": 23.5}, "timestamp": "2024-12-01T14:30:00Z"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-push-timestamp').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Events stored successfully","count":1}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/event/push</code>
                            <span class="text-muted">- Push batch events</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-push-batch">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/event/push \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"events":[{"data":{"temp":23.5},"timestamp":"2024-12-04T10:00:00Z"},{"data":{"temp":23.7}}]}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-push-batch').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Events stored successfully","count":2}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: #dbeafe; color: #1e40af;">POST</span>
                            <code style="color: var(--text-primary);">/api/event/query</code>
                            <span class="text-muted">- Query by date range</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-query">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/event/query \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"start_date":"2024-12-01T00:00:00Z","end_date":"2024-12-04T23:59:59Z","limit":100}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-query').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","total":150,"count":100,"events":[{"id":"123","event_data":{"temp":23.5},"event_timestamp":"..."}]}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-success" style="background: #d1fae5; color: #065f46;">GET</span>
                            <code style="color: var(--text-primary);">/api/event/stats</code>
                            <span class="text-muted">- Get statistics</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-stats">curl <?= htmlspecialchars($apiBaseUrl) ?>/event/stats \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>"</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-stats').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","total_events":1523,"earliest_event":"2024-11-01...","latest_event":"2024-12-04...","daily_stats_last_30_days":[...]}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-danger" style="background: #fee2e2; color: #991b1b;">DELETE</span>
                            <code style="color: var(--text-primary);">/api/event/clear</code>
                            <span class="text-muted">- Clear all events</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-clear-events">curl -X DELETE <?= htmlspecialchars($apiBaseUrl) ?>/event/clear \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>"</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-clear-events').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"All events cleared successfully","deleted_count":42}</pre>
                        </div>
                        <div class="alert alert-warning" style="margin-top: 0.75rem; font-size: 0.875rem;">
                            <strong>⚠️ Warning:</strong> This operation is irreversible and will delete all events associated with this API key.
                        </div>
                    </div>
                </div>
                
                <div class="alert" style="margin-top: 2rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2);">
                    <strong>💡 Use Cases:</strong> IoT sensor data, application events, analytics tracking, monitoring metrics, audit logs, and any time-series data.
                </div>
                
                <hr style="margin: 3rem 0; border: none; border-top: 1px solid var(--border-color);">
                
                <h3 style="margin-bottom: 1.5rem; color: var(--text-primary); font-size: 1.25rem; font-weight: 600;">⚡ Schema API (Event Optimization)</h3>
                <p style="color: var(--text-muted); margin-bottom: 1rem;">
                    Define event schemas to enable <strong>sub-millisecond aggregation queries</strong> on millions of events. 
                    When a schema is defined, events are automatically aggregated into hourly/daily statistics tables on push.
                </p>
                <div class="alert" style="margin-bottom: 2rem; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); font-size: 0.875rem;">
                    <strong>📊 Supported Field Types:</strong> 
                    <code>integer</code> (SMALLINT), 
                    <code>bigint</code> (BIGINT), 
                    <code>float</code> (~7 digits), 
                    <code>double</code> (~15 digits), 
                    <code>string</code> (VARCHAR 255), 
                    <code>boolean</code>
                </div>
                
                <div style="display: grid; gap: 2rem;">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: rgba(99, 102, 241, 0.2); color: #a78bfa;">POST</span>
                            <code style="color: var(--text-primary);">/api/schema</code>
                            <span class="text-muted">- Define schema</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-schema-define">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/schema \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"fields": [{"name": "cpm", "type": "integer"}, {"name": "usvh", "type": "float"}], "aggregations": ["hourly", "daily"]}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-schema-define').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Schema created successfully","schema":{"fields":[...],"aggregations":["hourly","daily"]}}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-success" style="background: #d1fae5; color: #065f46;">GET</span>
                            <code style="color: var(--text-primary);">/api/schema</code>
                            <span class="text-muted">- Get status</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-schema-get">curl <?= htmlspecialchars($apiBaseUrl) ?>/schema \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>"</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-schema-get').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","schema":{"fields":[{"name":"cpm","type":"integer","stats":{"avg":34.2,"min":12,"max":89}}],"aggregations":{"hourly":{"status":"active","row_count":168}}}}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: rgba(99, 102, 241, 0.2); color: #a78bfa;">POST</span>
                            <code style="color: var(--text-primary);">/api/event/aggregate</code>
                            <span class="text-muted">- Query aggregated data</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-aggregate">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/event/aggregate \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>" \
  -H "Content-Type: application/json" \
  -d '{"granularity": "hourly", "start_date": "2024-12-01", "end_date": "2024-12-10"}'</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-aggregate').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","granularity":"hourly","count":168,"data":[{"period":"2024-12-01 00:00:00","event_count":42,"avg_cpm":34.5,"min_cpm":12,"max_cpm":89}...]}</pre>
                        </div>
                    </div>
                    
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="badge badge-primary" style="background: rgba(99, 102, 241, 0.2); color: #a78bfa;">POST</span>
                            <code style="color: var(--text-primary);">/api/schema/rebuild</code>
                            <span class="text-muted">- Rebuild aggregations</span>
                        </div>
                        <div class="code-block">
                            <div id="cmd-schema-rebuild">curl -X POST <?= htmlspecialchars($apiBaseUrl) ?>/schema/rebuild \
  -H "X-API-Key: <span class="doc-api-key"><?= htmlspecialchars($latestApiKey) ?></span>"</div>
                            <button class="copy-btn" onclick="copyToClipboard(document.getElementById('cmd-schema-rebuild').innerText, this)" title="Copy Command">📋</button>
                        </div>
                        <div class="response-block">
                            <div class="response-label">Sample Response</div>
                            <pre style="margin: 0; color: #a5b4fc;">{"status":"success","message":"Aggregations rebuilt successfully","rebuilt":{"hourly":168,"daily":30}}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- API Playground Section -->
        <div id="playground" class="section">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🧪 API Playground</h2>
                <span class="text-muted text-sm">Test API endpoints in real-time</span>
            </div>
            <div class="card-body">
                <div class="playground-container">
                    <!-- Request Panel -->
                    <div class="playground-request">
                        <div class="playground-row">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">API Key</label>
                                <select id="playgroundApiKey" class="form-control playground-select">
                                    <?php foreach ($apiKeys as $key): ?>
                                    <option value="<?= htmlspecialchars($key['api_key']) ?>" 
                                            data-name="<?= htmlspecialchars($key['name'] ?: 'Unnamed Key') ?>">
                                        <?= htmlspecialchars($key['name'] ?: substr($key['api_key'], 0, 12) . '...') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 2;">
                                <label class="form-label">Endpoint</label>
                                <div class="playground-endpoint-wrapper">
                                    <span class="playground-method" id="playgroundMethod">POST</span>
                                    <select id="playgroundEndpoint" class="form-control playground-endpoint-select" onchange="updatePlaygroundEndpoint()">
                                        <optgroup label="Key/Value Store">
                                            <option value="set" data-method="POST" data-body='{"value": "Hello, World!"}' data-joke="true" selected>
                                                /api/set - Store a value
                                            </option>
                                            <option value="set-update" data-method="POST" data-body='{"key": "YOUR_KEY_UUID", "value": "Updated value"}'>
                                                /api/set - Update existing key
                                            </option>
                                            <option value="get" data-method="POST" data-body='{"key": "YOUR_KEY_UUID"}'>
                                                /api/get - Retrieve a value
                                            </option>
                                            <option value="exists" data-method="POST" data-body='{"key": "YOUR_KEY_UUID"}'>
                                                /api/exists - Check if key exists
                                            </option>
                                            <option value="list" data-method="GET" data-body="">
                                                /api/list - List all keys
                                            </option>
                                            <option value="delete" data-method="POST" data-body='{"key": "YOUR_KEY_UUID"}'>
                                                /api/delete - Delete a key
                                            </option>
                                        </optgroup>
                                        <optgroup label="Event Data">
                                            <option value="event/push" data-method="POST" data-body='{"data": {"temperature": 23.5, "humidity": 65}}'>
                                                /api/event/push - Push single event
                                            </option>
                                            <option value="event/push-timestamp" data-method="POST" data-body='{"data": {"temperature": 23.5, "humidity": 65}, "timestamp": "2024-12-01T14:30:00Z"}'>
                                                /api/event/push - Push with custom timestamp
                                            </option>
                                            <option value="event/push-batch" data-method="POST" data-body='{"events": [{"data": {"temp": 23.5}, "timestamp": "2024-12-01T10:00:00Z"}, {"data": {"temp": 24.0}}]}'>
                                                /api/event/push - Push batch events
                                            </option>
                                            <option value="event/query" data-method="POST" data-body='{"start_date": "2024-01-01T00:00:00Z", "end_date": "2024-12-31T23:59:59Z", "limit": 100}'>
                                                /api/event/query - Query events
                                            </option>
                                            <option value="event/stats" data-method="GET" data-body="">
                                                /api/event/stats - Get event statistics
                                            </option>
                                            <option value="event/aggregate" data-method="POST" data-body='{"granularity": "hourly", "start_date": "2024-12-01", "end_date": "2024-12-10"}'>
                                                /api/event/aggregate - Query aggregated data
                                            </option>
                                        </optgroup>
                                        <optgroup label="⚡ Schema API (Optimization)">
                                            <option value="schema" data-method="POST" data-body='{"fields": [{"name": "cpm", "type": "integer"}, {"name": "usvh", "type": "float"}], "aggregations": ["hourly", "daily"]}'>
                                                /api/schema - Define event schema
                                            </option>
                                            <option value="schema-get" data-method="GET" data-body="">
                                                /api/schema - Get schema status
                                            </option>
                                            <option value="schema/rebuild" data-method="POST" data-body="">
                                                /api/schema/rebuild - Rebuild aggregations
                                            </option>
                                        </optgroup>
                                        <optgroup label="⚠️ Destructive Operations">
                                            <option value="clear" data-method="DELETE" data-body="" data-destructive="true">
                                                /api/clear - Clear all K/V data
                                            </option>
                                            <option value="event/clear" data-method="DELETE" data-body="" data-destructive="true">
                                                /api/event/clear - Clear all events
                                            </option>
                                            <option value="schema-delete" data-method="DELETE" data-body="" data-destructive="true">
                                                /api/schema - Delete schema
                                            </option>
                                        </optgroup>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group" id="playgroundBodyGroup">
                            <label class="form-label">
                                Request Body <span class="text-muted">(JSON)</span>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="formatPlaygroundJson()" title="Format JSON" style="padding: 0.25rem 0.5rem; margin-left: 0.5rem;">
                                    ✨ Format
                                </button>
                            </label>
                            <textarea id="playgroundBody" class="form-control playground-textarea" rows="6" spellcheck="false">{"value": "Hello, World!"}</textarea>
                            <div id="playgroundBodyError" class="playground-error"></div>
                        </div>
                        
                        <div class="playground-actions">
                            <button type="button" class="btn btn-primary playground-send-btn" onclick="sendPlaygroundRequest()" id="playgroundSendBtn">
                                <span class="btn-icon-send">🚀</span>
                                <span>Send Request</span>
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetPlayground()">
                                <span>↺</span>
                                <span>Reset</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Response Panel -->
                    <div class="playground-response">
                        <div class="playground-response-header">
                            <span class="form-label" style="margin-bottom: 0;">Response</span>
                            <div class="playground-response-meta" id="playgroundResponseMeta">
                                <span class="playground-status" id="playgroundStatus">—</span>
                                <span class="playground-time" id="playgroundTime">—</span>
                            </div>
                        </div>
                        
                        <div class="playground-response-tabs">
                            <button class="playground-tab active" onclick="switchResponseTab('body')" data-tab="body">Body</button>
                            <button class="playground-tab" onclick="switchResponseTab('headers')" data-tab="headers">Headers</button>
                            <button class="playground-tab" onclick="switchResponseTab('raw')" data-tab="raw">Raw</button>
                        </div>
                        
                        <div class="playground-response-body">
                            <div id="playgroundResponseBody" class="playground-response-content active">
                                <div class="playground-empty-response">
                                    <span class="playground-empty-icon">📡</span>
                                    <p>Send a request to see the response</p>
                                </div>
                            </div>
                            <div id="playgroundResponseHeaders" class="playground-response-content">
                                <div class="playground-empty-response">
                                    <span class="playground-empty-icon">📋</span>
                                    <p>No headers yet</p>
                                </div>
                            </div>
                            <div id="playgroundResponseRaw" class="playground-response-content">
                                <div class="playground-empty-response">
                                    <span class="playground-empty-icon">📄</span>
                                    <p>No raw response yet</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="playground-response-actions" id="playgroundResponseActions" style="display: none;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="copyPlaygroundResponse()">
                                📋 Copy Response
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="playground-tips">
                    <div class="playground-tip">
                        <span class="playground-tip-icon">💡</span>
                        <span>Use <code>YOUR_KEY_UUID</code> as a placeholder — replace it with an actual key from your stored data.</span>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Data Section -->
        <div id="viewdata" class="section">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">💾 Data</h2>
                    <div class="flex items-center gap-2">
                        <span class="badge badge-neutral hidden-mobile" id="kvFilterBadge" style="display: none;">Filtered</span>
                        <input type="text" id="kvSearchInput" class="form-control" placeholder="Search keys or values..." style="width: 250px;">
                        <button onclick="exportKvData()" class="btn btn-secondary btn-sm" title="Export to JSON">
                            <span>📥</span>
                            <span class="hidden-mobile">Export JSON</span>
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <?php if (empty($kvPairs)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">📭</span>
                            <p>No stored keys yet. Use the API to store some data!</p>
                        </div>
                    <?php else: ?>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Value Preview</th>
                                    <th>API Key</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="kvTableBody">
                                <?php foreach ($kvPairs as $kv): ?>
                                    <tr class="kv-row" 
                                        data-id="<?= htmlspecialchars($kv['id']) ?>"
                                        data-key="<?= htmlspecialchars($kv['key']) ?>"
                                        data-value="<?= htmlspecialchars($kv['value']) ?>"
                                        data-api-key="<?= htmlspecialchars($kv['api_key']) ?>"
                                        data-api-key-id="<?= htmlspecialchars($kv['api_key_id']) ?>"
                                        data-api-key-name="<?= htmlspecialchars($kv['api_key_name'] ?? '') ?>"
                                        data-created="<?= htmlspecialchars($kv['created_at']) ?>"
                                        data-updated="<?= htmlspecialchars($kv['updated_at']) ?>">
                                        <td data-label="Key">
                                            <code class="text-muted" title="<?= htmlspecialchars($kv['key']) ?>" style="word-break: break-all;"><?= htmlspecialchars(truncateKey($kv['key'])) ?></code>
                                        </td>
                                        <td data-label="Value">
                                            <div class="btn btn-ghost btn-sm text-left" style="justify-content: flex-start; max-width: 250px; overflow: hidden; text-overflow: ellipsis; word-break: break-word;" onclick="showKvValueModal(this.closest('tr'))">
                                                <?= htmlspecialchars(truncateValue($kv['value'], 60)) ?>
                                            </div>
                                        </td>
                                        <td data-label="API Key">
                                            <span class="badge badge-neutral font-mono"><?= htmlspecialchars($kv['api_key_name'] ?: substr($kv['api_key'], 0, 8) . '...') ?></span>
                                        </td>
                                        <td data-label="Created" class="text-sm text-muted"><?= date('M j H:i', strtotime($kv['created_at'])) ?></td>
                                        <td data-label="Updated" class="text-sm text-muted kv-timestamp"><?= date('M j H:i', strtotime($kv['updated_at'])) ?></td>
                                        <td data-label="Actions" class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <button class="btn btn-secondary btn-icon" onclick="openKvEditModal(this.closest('tr'))" title="Edit">
                                                    ✏️
                                                </button>
                                                <button class="btn btn-danger btn-icon" onclick="deleteKvKey(this.closest('tr'))" title="Delete">
                                                    🗑️
                                                </button>
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

        <!-- Events Section -->
        <div id="events" class="section">
            <?php if ($eventsMigrationNeeded): ?>
            <div class="card" style="background: linear-gradient(135deg, #f59e0b20, #f59e0b10); border: 1px solid #f59e0b40; margin-bottom: 1.5rem;">
                <div class="card-body" style="padding: 1rem;">
                    <div class="flex items-center gap-3">
                        <span style="font-size: 1.5rem;">⚠️</span>
                        <div>
                            <strong style="color: #f59e0b;">Database Migration Required</strong>
                            <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                Event statistics tables are not yet created. Run the migration script (<code>scripts/db-migrate-stats.sh</code>) to enable event analytics.
                                With large datasets, this is required to prevent timeouts.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="stats-grid" style="margin-bottom: 1.5rem;">
                <div class="stat-card">
                    <div class="stat-label">Total Data Points<?php if ($filterApiKeyId): ?> <span class="badge badge-primary" style="font-size: 0.65rem; padding: 0.15rem 0.4rem;">Filtered</span><?php endif; ?></div>
                    <div class="stat-value" id="stat-event-total"><?= number_format($eventStats['total']) ?></div>
                    <div class="text-sm text-muted mt-2"><?= $filterApiKeyId ? 'Events for selected key' : 'All time events' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Velocity (Today)<?php if ($filterApiKeyId): ?> <span class="badge badge-primary" style="font-size: 0.65rem; padding: 0.15rem 0.4rem;">Filtered</span><?php endif; ?></div>
                    <div class="stat-value" id="stat-event-today" style="color: #34d399; background: none; -webkit-text-fill-color: initial;">
                        <?= number_format($eventStats['today']) ?>
                    </div>
                    <div class="text-sm text-muted mt-2"><?= $filterApiKeyId ? 'Today for selected key' : 'Events received last 24h' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Sources</div>
                    <div class="stat-value" id="stat-event-sources"><?= number_format($eventStats['active_sources']) ?></div>
                    <div class="text-sm text-muted mt-2"><?= $filterApiKeyId ? 'Selected key active' : 'Unique keys in last 7 days' ?></div>
                </div>
            </div>
            
            <?php if (!empty($commonKeys)): ?>
            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-semibold text-muted uppercase tracking-wide">Top Data Properties (Sampled from last 100)</h3>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($commonKeys as $key => $percent): ?>
                        <div class="key-badge">
                            <span class="font-mono text-primary"><?= htmlspecialchars($key) ?></span>
                            <span class="count"><?= $percent ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📈 Ingestion Volume</h2>
                    <span class="badge badge-neutral">Last 30 Days</span>
                </div>
                <div class="card-body">
                    <div id="event-volume-chart" style="width: 100%; height: 300px;"></div>
                </div>
            </div>
            
            <div class="card" id="events-table">
                <div class="card-header flex-wrap gap-4">
                    <div class="flex items-center gap-2">
                        <h2 class="card-title">📊 Event Stream</h2>
                        <span class="badge badge-neutral">Latest 30</span>
                    </div>

                    <button onclick="refreshEvents()" class="btn btn-secondary btn-icon" title="Refresh">🔄</button>
                    <button onclick="exportEvents()" class="btn btn-secondary btn-sm" title="Export to JSON">
                        <span>📥</span>
                        <span class="hidden-mobile">Export JSON</span>
                    </button>
                </div>
                
                <div class="table-responsive">
                    <?php if (empty($recentEvents)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">📡</span>
                            <p>No events found. Start pushing data to the Event API!</p>
                        </div>
                    <?php else: ?>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Source</th>
                                    <th>Payload Preview</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $event): ?>
                                    <tr class="event-row" style="cursor: pointer;" onclick='showEventModal(<?= json_encode($event) ?>)'>
                                        <td data-label="Timestamp" class="timestamp-col text-muted">
                                            <?= date('M j, H:i:s', strtotime($event['event_timestamp'])) ?>
                                            <span class="text-xs opacity-50">.<?= substr(formatMicrotime($event['event_timestamp']), 0, 3) ?></span>
                                        </td>
                                        <td data-label="Source">
                                            <span class="source-badge" title="<?= htmlspecialchars($event['api_key']) ?>">
                                                <?= htmlspecialchars($event['key_name'] ?: substr($event['api_key'], 0, 8).'...') ?>
                                            </span>
                                        </td>
                                        <td data-label="Payload">
                                            <div class="json-preview" style="max-width: 200px;">
                                                <?= htmlspecialchars(substr($event['event_data'], 0, 80)) ?><?= strlen($event['event_data']) > 80 ? '...' : '' ?>
                                            </div>
                                        </td>
                                        <td data-label="Actions" class="text-right">
                                            <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation(); showEventModal(<?= htmlspecialchars(json_encode($event)) ?>)">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Schemas Section -->
        <div id="schemas" class="section">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">⚡ Event Schema Optimization</h2>
                </div>
                <div class="card-body">
                    <div class="info-box" style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: var(--radius-sm); padding: 1rem; margin-bottom: 1.5rem;">
                        <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                            <strong style="color: var(--text-primary);">📊 Schema-based aggregation</strong> enables sub-millisecond queries on millions of events by pre-computing hourly and daily statistics for your defined fields.
                        </p>
                    </div>
                    
                    <div id="schemaContent">
                        <p class="text-muted">Select an API key using the filter above to manage its schema.</p>
                    </div>
                    
                    <!-- Schema Management Template (populated via JavaScript) -->
                    <template id="schemaTemplate">
                        <div class="schema-status" style="margin-bottom: 1.5rem;">
                            <div class="stats-grid" style="margin-bottom: 1rem;">
                                <div class="stat-card">
                                    <div class="stat-label">Schema Status</div>
                                    <div class="stat-value" id="schemaStatusValue">—</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Fields Defined</div>
                                    <div class="stat-value" id="schemaFieldsCount">0</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Hourly Stats</div>
                                    <div class="stat-value" id="schemaHourlyRows">—</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-label">Daily Stats</div>
                                    <div class="stat-value" id="schemaDailyRows">—</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Current Schema Display -->
                        <div id="currentSchemaSection" style="display: none; margin-bottom: 1.5rem;">
                            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary);">📋 Current Schema</h3>
                            <div class="table-responsive">
                                <table class="responsive-table" id="schemaFieldsTable">
                                    <thead>
                                        <tr>
                                            <th>Field Name</th>
                                            <th>Type</th>
                                            <th>Stats</th>
                                        </tr>
                                    </thead>
                                    <tbody id="schemaFieldsBody"></tbody>
                                </table>
                            </div>
                            
                            <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                                <button class="btn btn-secondary" onclick="rebuildSchema()" id="rebuildSchemaBtn">
                                    🔄 Rebuild Aggregations
                                </button>
                                <button class="btn btn-danger" onclick="deleteSchema()" id="deleteSchemaBtn">
                                    🗑️ Delete Schema
                                </button>
                            </div>
                        </div>
                        
                        <!-- Create Schema Form -->
                        <div id="createSchemaSection">
                            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary);">✨ Create New Schema</h3>
                            
                            <div id="schemaFieldsList" style="margin-bottom: 1rem;">
                                <div class="schema-field-row" style="display: flex; gap: 0.75rem; margin-bottom: 0.5rem; align-items: end;">
                                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                        <label class="form-label">Field Name</label>
                                        <input type="text" class="form-control schema-field-name" placeholder="e.g., cpm" pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                                    </div>
                                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                        <label class="form-label">Type</label>
                                        <select class="form-control schema-field-type">
                                            <option value="integer">integer (SMALLINT)</option>
                                            <option value="bigint">bigint (BIGINT)</option>
                                            <option value="float">float (FLOAT, ~7 digits)</option>
                                            <option value="double">double (DOUBLE, ~15 digits)</option>
                                            <option value="string">string (VARCHAR 255)</option>
                                            <option value="boolean">boolean</option>
                                        </select>
                                    </div>
                                    <button class="btn btn-danger btn-sm" onclick="removeSchemaField(this)" style="padding: 0.5rem;">✕</button>
                                </div>
                            </div>
                            
                            <button class="btn btn-secondary btn-sm" onclick="addSchemaField()" style="margin-bottom: 1rem;">
                                + Add Field
                            </button>
                            
                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label class="form-label">Aggregation Types</label>
                                <div style="display: flex; gap: 1rem;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" id="aggHourly" checked> Hourly
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" id="aggDaily" checked> Daily
                                    </label>
                                </div>
                            </div>
                            
                            <button class="btn btn-primary" onclick="createSchema()" id="createSchemaBtn">
                                ⚡ Create Schema & Enable Optimization
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Logs Section -->
        <div id="logs" class="section">
            <!-- Status Summary Cards -->
            <div class="stats-grid" style="margin-bottom: 1.5rem;">
                <div class="stat-card">
                    <div class="stat-label">Log Entries</div>
                    <div class="stat-value"><?= number_format($logStatusStats['total_count'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Successful (2xx)</div>
                    <div class="stat-value" style="color: #34d399; background: none; -webkit-text-fill-color: initial;">
                        <?= number_format($logStatusStats['success_count'] ?? 0) ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Client Errors (4xx)</div>
                    <div class="stat-value" style="color: #fbbf24; background: none; -webkit-text-fill-color: initial;">
                        <?= number_format($logStatusStats['client_error_count'] ?? 0) ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Server Errors (5xx)</div>
                    <div class="stat-value" style="color: #f87171; background: none; -webkit-text-fill-color: initial;">
                        <?= number_format($logStatusStats['server_error_count'] ?? 0) ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header" style="flex-wrap: wrap; gap: 1rem;">
                    <h2 class="card-title">📋 Request Logs</h2>
                    <div class="flex items-center gap-2" style="flex-wrap: wrap;">
                        <span class="badge badge-neutral" id="logsCountBadge"><?= number_format($requestLogsCount) ?> logs</span>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="logs-filters" style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; padding: 1rem; background: rgba(0,0,0,0.2); border-radius: var(--radius-sm);">
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 140px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">Status</label>
                        <select id="logStatusFilter" class="form-control" style="padding: 0.5rem;">
                            <option value="">All Status</option>
                            <option value="success" <?= $logFilters['status_filter'] === 'success' ? 'selected' : '' ?>>✅ Success (2xx)</option>
                            <option value="client_error" <?= $logFilters['status_filter'] === 'client_error' ? 'selected' : '' ?>>⚠️ Client Error (4xx)</option>
                            <option value="server_error" <?= $logFilters['status_filter'] === 'server_error' ? 'selected' : '' ?>>❌ Server Error (5xx)</option>
                            <option value="errors" <?= $logFilters['status_filter'] === 'errors' ? 'selected' : '' ?>>🚨 All Errors (4xx+5xx)</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 140px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">Endpoint</label>
                        <select id="logEndpointFilter" class="form-control" style="padding: 0.5rem;">
                            <option value="">All Endpoints</option>
                            <?php foreach ($uniqueEndpoints as $ep): ?>
                                <option value="<?= htmlspecialchars($ep) ?>" <?= $logFilters['endpoint'] === $ep ? 'selected' : '' ?>><?= htmlspecialchars($ep) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 120px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">Method</label>
                        <select id="logMethodFilter" class="form-control" style="padding: 0.5rem;">
                            <option value="">All Methods</option>
                            <option value="GET" <?= $logFilters['method'] === 'GET' ? 'selected' : '' ?>>GET</option>
                            <option value="POST" <?= $logFilters['method'] === 'POST' ? 'selected' : '' ?>>POST</option>
                            <option value="DELETE" <?= $logFilters['method'] === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                        </select>
                    </div>
                    
                    <?php if (count($apiKeys) > 1): ?>
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 160px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">API Key</label>
                        <select id="logApiKeyFilter" class="form-control" style="padding: 0.5rem;">
                            <option value="">All API Keys</option>
                            <?php foreach ($apiKeys as $key): ?>
                                <option value="<?= $key['id'] ?>" <?= $logFilters['api_key_id'] == $key['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($key['name'] ?: substr($key['api_key'], 0, 8) . '...') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">From Date</label>
                        <input type="date" id="logDateFrom" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($logFilters['date_from']) ?>">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
                        <label class="form-label" style="font-size: 0.7rem; margin-bottom: 0.25rem;">To Date</label>
                        <input type="date" id="logDateTo" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($logFilters['date_to']) ?>">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0; display: flex; align-items: flex-end; gap: 0.5rem;">
                        <button onclick="applyLogFilters()" class="btn btn-primary btn-sm" style="height: 36px;">
                            🔍 Filter
                        </button>
                        <button onclick="clearLogFilters()" class="btn btn-secondary btn-sm" style="height: 36px;">
                            ✕ Clear
                        </button>
                    </div>
                </div>
                
                <!-- Logs Table -->
                <div class="table-responsive">
                    <?php if (empty($requestLogs)): ?>
                        <div class="empty-state">
                            <span class="empty-icon">📋</span>
                            <?php if (!empty(array_filter($logFilters))): ?>
                                <p>No logs match your filters</p>
                                <div class="mt-4">
                                    <button onclick="clearLogFilters()" class="btn btn-secondary">Clear Filters</button>
                                </div>
                            <?php else: ?>
                                <p>No API request logs yet. Start using the API!</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table id="logsTable" class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Method</th>
                                    <th>Endpoint</th>
                                    <th>Status</th>
                                    <th>API Key</th>
                                </tr>
                            </thead>
                            <tbody id="logsTableBody">
                                <?php foreach ($requestLogs as $log): ?>
                                    <?php
                                        $statusClass = 'badge-neutral';
                                        $statusIcon = '';
                                        if ($log['status_code'] >= 200 && $log['status_code'] < 300) {
                                            $statusClass = 'badge-success';
                                            $statusIcon = '✅';
                                        } elseif ($log['status_code'] >= 400 && $log['status_code'] < 500) {
                                            $statusClass = 'badge-warning';
                                            $statusIcon = '⚠️';
                                        } elseif ($log['status_code'] >= 500) {
                                            $statusClass = 'badge-danger';
                                            $statusIcon = '❌';
                                        }
                                        
                                        $methodClass = 'method-' . strtolower($log['method']);
                                    ?>
                                    <tr class="log-row">
                                        <td data-label="Timestamp" class="timestamp-col text-muted">
                                            <?= date('M j, H:i:s', strtotime($log['created_at'])) ?>
                                        </td>
                                        <td data-label="Method">
                                            <span class="method-badge <?= $methodClass ?>"><?= htmlspecialchars($log['method']) ?></span>
                                        </td>
                                        <td data-label="Endpoint">
                                            <code class="endpoint-badge"><?= htmlspecialchars($log['endpoint']) ?></code>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge <?= $statusClass ?>">
                                                <?= $statusIcon ?> <?= $log['status_code'] ?>
                                            </span>
                                        </td>
                                        <td data-label="API Key">
                                            <span class="source-badge" title="<?= htmlspecialchars($log['api_key']) ?>">
                                                <?= htmlspecialchars($log['api_key_name'] ?: substr($log['api_key'], 0, 8) . '...') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($requestLogsTotalPages > 1): ?>
                        <div class="logs-pagination" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-top: 1px solid var(--border-color); margin-top: 1rem;">
                            <div class="text-muted" style="font-size: 0.875rem;">
                                Showing <?= number_format($logsOffset + 1) ?> - <?= number_format(min($logsOffset + $logsLimit, $requestLogsCount)) ?> of <?= number_format($requestLogsCount) ?> logs
                            </div>
                            <div class="flex gap-2">
                                <?php if ($logsPage > 1): ?>
                                    <button onclick="goToLogPage(1)" class="btn btn-secondary btn-sm" title="First">⏮</button>
                                    <button onclick="goToLogPage(<?= $logsPage - 1 ?>)" class="btn btn-secondary btn-sm" title="Previous">◀</button>
                                <?php endif; ?>
                                
                                <span class="badge badge-neutral" style="display: flex; align-items: center; padding: 0.5rem 0.75rem;">
                                    Page <?= $logsPage ?> of <?= $requestLogsTotalPages ?>
                                </span>
                                
                                <?php if ($logsPage < $requestLogsTotalPages): ?>
                                    <button onclick="goToLogPage(<?= $logsPage + 1 ?>)" class="btn btn-secondary btn-sm" title="Next">▶</button>
                                    <button onclick="goToLogPage(<?= $requestLogsTotalPages ?>)" class="btn btn-secondary btn-sm" title="Last">⏭</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <!-- KV Store Modals -->
    <div id="kvValueModal" class="modal" onclick="closeKvValueModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="card-title">Value Details</h3>
                <button class="modal-close" onclick="closeKvValueModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="card" style="background: rgba(0,0,0,0.2); margin-bottom: 1rem;">
                    <div class="flex justify-between mb-2 border-bottom pb-2" style="border-color: var(--border-color);">
                        <span class="text-muted">Full Key</span>
                        <code class="text-primary" id="kvModalKeyFull"></code>
                    </div>
                    <div class="flex justify-between mb-2">
                        <span class="text-muted">API Key</span>
                        <span class="font-mono" id="kvModalApiKey"></span>
                    </div>
                    <div class="flex justify-between mb-2">
                        <span class="text-muted">Created</span>
                        <span id="kvModalCreated"></span>
                    </div>
                    <div class="flex justify-between mb-2">
                        <span class="text-muted">Updated</span>
                        <span id="kvModalUpdated"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">Size</span>
                        <span id="kvModalSize"></span>
                    </div>
                </div>
                <div class="form-label">Full Value Content</div>
                <div class="code-block" id="kvModalValue" style="white-space: pre-wrap; max-height: 300px; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeKvValueModal()">Close</button>
            </div>
        </div>
    </div>
    
    <div id="kvEditModal" class="modal" onclick="closeKvEditModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="card-title">✏️ Edit Value</h3>
                <button class="modal-close" onclick="closeKvEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Key (read-only)</label>
                    <input type="text" id="kvEditKey" class="form-control" readonly style="opacity: 0.7;">
                </div>
                <div class="form-group">
                    <label class="form-label">Value</label>
                    <textarea id="kvEditValue" class="form-control" placeholder="Enter new value..." style="min-height: 200px; font-family: var(--font-mono);"></textarea>
                </div>
                <div class="text-right text-muted" style="font-size: 0.75rem;">
                    Size: <span id="kvEditValueSize">0 B</span> / <?= $maxValueSizeKB ?> KB
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeKvEditModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveKvEdit()" id="kvSaveBtn">Save Changes</button>
            </div>
        </div>
    </div>
    
    <!-- Event Explorer Modal -->
    <div id="eventModal" class="modal" onclick="closeEventModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="card-title">Event Details</h3>
                <button class="modal-close" onclick="closeEventModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="flex justify-between mb-4 text-sm">
                     <div>
                         <div class="text-muted">Timestamp</div>
                         <div class="font-mono text-primary" id="eventModalTimestamp"></div>
                     </div>
                     <div class="text-right">
                         <div class="text-muted">Source Key</div>
                         <div class="font-mono text-primary" id="eventModalSource"></div>
                     </div>
                </div>
                <div class="form-label">Payload</div>
                <div class="code-block" id="eventModalPayload" style="max-height: 400px; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEventModal()">Close</button>
            </div>
        </div>
    </div>
    
    <footer class="container text-center text-muted" style="padding: 2rem 0; font-size: 0.875rem;">
        &copy; <?= date('Y') ?> Kibotu Services. All rights reserved.
    </footer>

    <script src="<?= htmlspecialchars(getBasePath()) ?>/js/d3.v7.min.js"></script>
    <script>
        // CSRF token for AJAX requests
        const csrfToken = '<?= htmlspecialchars(getCSRFToken()) ?>';
        
        function copyToClipboard(text, button) {
            // Remove any trailing newlines that might have been added by div structure
            text = text.trim();
            
            navigator.clipboard.writeText(text).then(() => {
                const originalContent = button.innerHTML;
                button.innerHTML = '✓';
                button.style.color = '#10b981';
                button.style.borderColor = '#10b981';
                
                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.style.color = '';
                    button.style.borderColor = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
                button.innerHTML = '❌';
                setTimeout(() => button.innerHTML = '📋', 2000);
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const dailyStats = <?= json_encode($usageStats['daily'] ?? []) ?>;
            const endpointStats = <?= json_encode($usageStats['endpoints'] ?? []) ?>;
            const storageStats = <?= json_encode(array_map(function($k) {
                return [
                    'name' => $k['name'] ?: substr($k['api_key'], 0, 8) . '...',
                    'kv_bytes' => (int)$k['kv_storage_bytes'],
                    'event_bytes' => (int)$k['event_storage_bytes'],
                    'total_bytes' => (int)$k['total_storage_bytes']
                ];
            }, $apiKeys)) ?>;
            
            // Helper to check if data exists
            const hasData = dailyStats && dailyStats.length > 0;

            // API Key Selection in Docs
            const docsKeySelect = document.getElementById('docs_api_key');
            if (docsKeySelect) {
                docsKeySelect.addEventListener('change', function(e) {
                    const newKey = e.target.value;
                    document.querySelectorAll('.doc-api-key').forEach(el => {
                        el.textContent = newKey;
                    });
                });
                
                // Prevent click on select from toggling the accordion
                docsKeySelect.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            // Create tooltip div
            const tooltip = d3.select("body").append("div")
                .attr("class", "d3-tooltip")
                .style("opacity", 0)
                .style("position", "absolute")
                .style("background", "rgba(17, 24, 39, 0.9)")
                .style("color", "#f3f4f6")
                .style("padding", "8px 12px")
                .style("border-radius", "6px")
                .style("font-size", "12px")
                .style("pointer-events", "none")
                .style("box-shadow", "0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)")
                .style("z-index", "100")
                .style("border", "1px solid rgba(255, 255, 255, 0.1)");

            // --- Requests Chart (Area Chart) ---
            const requestsContainer = document.getElementById('requests-chart');
            
            if (!hasData) {
                requestsContainer.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);">No data available</div>';
                document.getElementById('endpoints-chart').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);">No data available</div>';
                return;
            }
            
            const margin = {top: 20, right: 20, bottom: 30, left: 40};
            const width = requestsContainer.clientWidth - margin.left - margin.right;
            const height = requestsContainer.clientHeight - margin.top - margin.bottom;

            const svgRequests = d3.select("#requests-chart")
                .append("svg")
                .attr("width", width + margin.left + margin.right)
                .attr("height", height + margin.top + margin.bottom)
                .append("g")
                .attr("transform", `translate(${margin.left},${margin.top})`);

            // Parse dates
            const parseDate = d3.timeParse("%Y-%m-%d");
            const formatDate = d3.timeFormat("%b %d");
            dailyStats.forEach(d => d.dateObj = parseDate(d.date));

            // X Axis
            const x = d3.scaleTime()
                .domain(d3.extent(dailyStats, d => d.dateObj))
                .range([0, width]);
            
            svgRequests.append("g")
                .attr("transform", `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(5).tickFormat(formatDate))
                .attr("color", "#6b7280")
                .style("font-family", "Inter, sans-serif")
                .select(".domain").remove();

            // Y Axis
            const y = d3.scaleLinear()
                .domain([0, d3.max(dailyStats, d => +d.total_requests) * 1.1])
                .range([height, 0]);
            
            svgRequests.append("g")
                .call(d3.axisLeft(y).ticks(5))
                .attr("color", "#6b7280")
                .style("font-family", "Inter, sans-serif")
                .select(".domain").remove();

            // Grid lines
            svgRequests.append("g")
                .attr("class", "grid")
                .attr("stroke", "rgba(255, 255, 255, 0.05)")
                .attr("stroke-dasharray", "3,3")
                .call(d3.axisLeft(y)
                    .ticks(5)
                    .tickSize(-width)
                    .tickFormat("")
                )
                .select(".domain").remove();

            // Gradient
            const gradient = svgRequests.append("defs")
                .append("linearGradient")
                .attr("id", "areaGradient")
                .attr("x1", "0%")
                .attr("y1", "0%")
                .attr("x2", "0%")
                .attr("y2", "100%");

            gradient.append("stop")
                .attr("offset", "0%")
                .attr("stop-color", "#3b82f6")
                .attr("stop-opacity", 0.5);

            gradient.append("stop")
                .attr("offset", "100%")
                .attr("stop-color", "#3b82f6")
                .attr("stop-opacity", 0);

            // Area
            svgRequests.append("path")
                .datum(dailyStats)
                .attr("fill", "url(#areaGradient)")
                .attr("stroke", "#3b82f6")
                .attr("stroke-width", 2)
                .attr("d", d3.area()
                    .x(d => x(d.dateObj))
                    .y0(height)
                    .y1(d => y(d.total_requests))
                    .curve(d3.curveMonotoneX)
                );

            // Add interactive circles
            svgRequests.selectAll("dot")
                .data(dailyStats)
                .enter()
                .append("circle")
                .attr("cx", d => x(d.dateObj))
                .attr("cy", d => y(d.total_requests))
                .attr("r", 4)
                .attr("fill", "#3b82f6")
                .attr("stroke", "#1f2937")
                .attr("stroke-width", 2)
                .style("opacity", 0) // Hidden by default, shown on hover of area/overlay
                .on("mouseover", function(event, d) {
                    d3.select(this)
                        .transition()
                        .duration(200)
                        .attr("r", 6)
                        .style("opacity", 1);
                    
                    tooltip.transition()
                        .duration(200)
                        .style("opacity", 1);
                        
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
                .on("mouseout", function(d) {
                    d3.select(this)
                        .transition()
                        .duration(200)
                        .attr("r", 4)
                        .style("opacity", 0);
                        
                    tooltip.transition()
                        .duration(500)
                        .style("opacity", 0);
                });

            // Add invisible overlay for better hover detection if needed, 
            // but circles on top with 0 opacity works well for discrete points.
            // Let's make the circles always visible but small for better UX
            svgRequests.selectAll("circle")
                .style("opacity", 1)
                .attr("fill", "#1f2937") // Dark bg
                .attr("stroke", "#3b82f6"); // Blue stroke

            // Helper function for bytes formatting in JS
            function formatBytesJS(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // --- Endpoints Chart (Bar Chart) ---
            if (endpointStats && endpointStats.length > 0) {
                const epContainer = document.getElementById('endpoints-chart');
                // Adjust margins for horizontal bar chart labels
                const epMargin = {top: 20, right: 20, bottom: 30, left: 100}; 
                const epWidth = epContainer.clientWidth - epMargin.left - epMargin.right;
                const epHeight = epContainer.clientHeight - epMargin.top - epMargin.bottom;
                
                const svgEp = d3.select("#endpoints-chart")
                    .append("svg")
                    .attr("width", epWidth + epMargin.left + epMargin.right)
                    .attr("height", epHeight + epMargin.top + epMargin.bottom)
                    .append("g")
                    .attr("transform", `translate(${epMargin.left},${epMargin.top})`);
                
                // X Axis
                const xEp = d3.scaleLinear()
                    .domain([0, d3.max(endpointStats, d => +d.count)])
                    .range([0, epWidth]);
                
                svgEp.append("g")
                    .attr("transform", `translate(0,${epHeight})`)
                    .call(d3.axisBottom(xEp).ticks(5))
                    .attr("color", "#6b7280")
                    .style("font-family", "Inter, sans-serif")
                    .select(".domain").remove();
                
                // Y Axis
                const yEp = d3.scaleBand()
                    .range([0, epHeight])
                    .domain(endpointStats.map(d => d.endpoint))
                    .padding(.3);
                
                svgEp.append("g")
                    .call(d3.axisLeft(yEp))
                    .attr("color", "#6b7280")
                    .style("font-family", "Inter, sans-serif")
                    .select(".domain").remove();
                
                // Bars
                svgEp.selectAll("myRect")
                    .data(endpointStats)
                    .join("rect")
                    .attr("x", xEp(0) )
                    .attr("y", d => yEp(d.endpoint))
                    .attr("width", d => xEp(d.count))
                    .attr("height", yEp.bandwidth())
                    .attr("fill", "#10b981")
                    .attr("rx", 4)
                    .on("mouseover", function(event, d) {
                        d3.select(this)
                            .transition()
                            .duration(200)
                            .attr("fill", "#34d399");
                            
                        tooltip.transition()
                            .duration(200)
                            .style("opacity", 1);
                            
                        tooltip.html(`
                            <div style="font-weight: 600; margin-bottom: 4px;">${d.endpoint}</div>
                            <div style="display: flex; justify-content: space-between; gap: 12px;">
                                <span>Requests:</span> <span style="font-weight: 600; color: #34d399;">${d.count}</span>
                            </div>
                        `)
                        .style("left", (event.pageX + 10) + "px")
                        .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function(d) {
                        d3.select(this)
                            .transition()
                            .duration(200)
                            .attr("fill", "#10b981");
                            
                        tooltip.transition()
                            .duration(500)
                            .style("opacity", 0);
                    });
            }
        });

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
            
            // Save active tab to localStorage
            localStorage.setItem('adminActiveTab', sectionId);
            
            // Render event chart when events tab is shown (needs visible container for dimensions)
            if (sectionId === 'events') {
                renderEventChart();
            }
        }

        // Restore active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTab = localStorage.getItem('adminActiveTab');
            if (savedTab && document.getElementById(savedTab)) {
                showSection(savedTab);
            }
            
            // Initialize event chart if on events tab
            if (savedTab === 'events') {
                renderEventChart();
            }
            
            // Restore global API key filter
            const savedFilterId = localStorage.getItem('globalApiKeyFilterId');
            const globalFilter = document.getElementById('globalApiKeyFilter');
            if (globalFilter && savedFilterId) {
                globalFilter.value = savedFilterId;
                applyGlobalFilter(savedFilterId);
            }
        });
        
        // --- Global API Key Filter ---
        const globalApiKeyFilter = document.getElementById('globalApiKeyFilter');
        if (globalApiKeyFilter) {
            globalApiKeyFilter.addEventListener('change', function(e) {
                const filterId = e.target.value;
                const filterKey = filterId ? e.target.options[e.target.selectedIndex].dataset.key : '';
                
                // Save to localStorage
                if (filterId) {
                    localStorage.setItem('globalApiKeyFilterId', filterId);
                    localStorage.setItem('globalApiKeyFilterKey', filterKey);
                } else {
                    localStorage.removeItem('globalApiKeyFilterId');
                    localStorage.removeItem('globalApiKeyFilterKey');
                }
                
                applyGlobalFilter(filterId, filterKey);
            });
        }
        
        function applyGlobalFilter(filterId, filterKey) {
            const selectedOption = globalApiKeyFilter?.options[globalApiKeyFilter.selectedIndex];
            const actualFilterKey = filterKey || (selectedOption ? selectedOption.dataset.key : '');
            
            // Update Documentation tab - replace API key in all code examples
            updateDocumentationFilter(actualFilterKey);
            
            // Update Playground tab - sync API key selector
            updatePlaygroundApiKey(actualFilterKey);
            
            // Update View Data tab - filter table rows
            updateViewDataFilter(filterId);
            
            // Update Event Explorer tab - filter rows and update badge
            updateEventExplorerFilter(filterId);
            
            // Update Schema tab - load schema for selected API key
            updateSchemaSection(filterId, actualFilterKey);
        }
        
        function updateDocumentationFilter(filterKey) {
            const apiKey = filterKey || '<?= $latestApiKey ?>';
            document.querySelectorAll('.doc-api-key').forEach(el => {
                el.textContent = apiKey;
            });
        }
        
        function updatePlaygroundApiKey(filterKey) {
            const playgroundApiKeySelect = document.getElementById('playgroundApiKey');
            if (!playgroundApiKeySelect || !filterKey) return;
            
            // Find and select the matching option
            for (let option of playgroundApiKeySelect.options) {
                if (option.value === filterKey) {
                    playgroundApiKeySelect.value = filterKey;
                    break;
                }
            }
        }
        
        function updateViewDataFilter(filterId) {
            const rows = document.querySelectorAll('.kv-row');
            const badge = document.getElementById('kvFilterBadge');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const rowApiKeyId = row.dataset.apiKeyId;
                const searchTerm = document.getElementById('kvSearchInput')?.value.toLowerCase() || '';
                const key = row.dataset.key.toLowerCase();
                const value = row.dataset.value.toLowerCase();
                const matchesSearch = !searchTerm || key.includes(searchTerm) || value.includes(searchTerm);
                const matchesFilter = !filterId || rowApiKeyId === filterId;
                
                if (matchesSearch && matchesFilter) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide filter badge
            if (badge) {
                if (filterId) {
                    badge.style.display = 'inline-flex';
                    badge.textContent = `Filtered (${visibleCount})`;
                } else {
                    badge.style.display = 'none';
                }
            }
        }
        
        function updateEventExplorerFilter(filterId) {
            const rows = document.querySelectorAll('.event-row');
            const badge = document.getElementById('eventFilterBadge');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const rowData = row.getAttribute('onclick');
                if (!rowData) return;
                
                // Extract API key from onclick data
                const match = rowData.match(/"api_key":"([^"]+)"/);
                const rowApiKey = match ? match[1] : '';
                
                // Get selected filter key
                const selectedOption = globalApiKeyFilter?.options[globalApiKeyFilter.selectedIndex];
                const filterKey = selectedOption ? selectedOption.dataset.key : '';
                
                const matchesFilter = !filterKey || rowApiKey === filterKey;
                
                if (matchesFilter) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show/hide filter badge
            if (badge) {
                if (filterId) {
                    badge.style.display = 'inline-flex';
                    badge.textContent = `Filtered (${visibleCount})`;
                } else {
                    badge.style.display = 'none';
                }
            }
        }
        
        // --- Schema Management Functions ---
        let currentSchemaApiKeyId = null;
        let currentSchemaApiKey = null;
        
        // Initialize schema section when filter changes
        function updateSchemaSection(filterId, filterKey) {
            currentSchemaApiKeyId = filterId;
            currentSchemaApiKey = filterKey;
            
            const schemaContent = document.getElementById('schemaContent');
            if (!schemaContent) return;
            
            if (!filterId) {
                schemaContent.innerHTML = '<p class="text-muted">Select an API key using the filter above to manage its schema.</p>';
                return;
            }
            
            // Load template
            const template = document.getElementById('schemaTemplate');
            if (!template) return;
            
            schemaContent.innerHTML = template.innerHTML;
            
            // Load schema status
            loadSchemaStatus(filterKey);
        }
        
        async function loadSchemaStatus(apiKey) {
            try {
                const response = await fetch('/api/schema/', {
                    method: 'GET',
                    headers: { 'X-API-Key': apiKey }
                });
                
                const data = await response.json();
                
                if (data.schema) {
                    // Has schema - show current schema, hide create form
                    document.getElementById('currentSchemaSection').style.display = 'block';
                    document.getElementById('createSchemaSection').style.display = 'none';
                    document.getElementById('schemaStatusValue').textContent = '✅ Active';
                    document.getElementById('schemaFieldsCount').textContent = data.schema.fields.length;
                    
                    // Populate hourly/daily stats
                    const hourly = data.schema.aggregations?.hourly;
                    const daily = data.schema.aggregations?.daily;
                    document.getElementById('schemaHourlyRows').textContent = hourly ? `${hourly.row_count.toLocaleString()} rows` : 'Not configured';
                    document.getElementById('schemaDailyRows').textContent = daily ? `${daily.row_count.toLocaleString()} rows` : 'Not configured';
                    
                    // Populate fields table
                    const tbody = document.getElementById('schemaFieldsBody');
                    tbody.innerHTML = data.schema.fields.map(f => `
                        <tr>
                            <td><code>${f.name}</code></td>
                            <td><span class="badge badge-neutral">${f.type}</span></td>
                            <td>${f.stats ? formatFieldStats(f.stats) : '—'}</td>
                        </tr>
                    `).join('');
                } else {
                    // No schema - show create form
                    document.getElementById('currentSchemaSection').style.display = 'none';
                    document.getElementById('createSchemaSection').style.display = 'block';
                    document.getElementById('schemaStatusValue').textContent = 'Not defined';
                    document.getElementById('schemaFieldsCount').textContent = '0';
                    document.getElementById('schemaHourlyRows').textContent = '—';
                    document.getElementById('schemaDailyRows').textContent = '—';
                }
            } catch (error) {
                console.error('Failed to load schema:', error);
                document.getElementById('schemaStatusValue').textContent = '❌ Error';
            }
        }
        
        function formatFieldStats(stats) {
            if (stats.avg !== undefined) {
                return `avg: ${stats.avg.toFixed(2)}, min: ${stats.min}, max: ${stats.max}, count: ${stats.count.toLocaleString()}`;
            }
            return `count: ${stats.count?.toLocaleString() || 0}`;
        }
        
        function addSchemaField() {
            const container = document.getElementById('schemaFieldsList');
            const row = document.createElement('div');
            row.className = 'schema-field-row';
            row.style.cssText = 'display: flex; gap: 0.75rem; margin-bottom: 0.5rem; align-items: end;';
            row.innerHTML = `
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <input type="text" class="form-control schema-field-name" placeholder="field_name" pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                </div>
                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                    <select class="form-control schema-field-type">
                        <option value="integer">integer</option>
                        <option value="bigint">bigint</option>
                        <option value="float" selected>float</option>
                        <option value="double">double</option>
                        <option value="string">string</option>
                        <option value="boolean">boolean</option>
                    </select>
                </div>
                <button class="btn btn-danger btn-sm" onclick="removeSchemaField(this)" style="padding: 0.5rem;">✕</button>
            `;
            container.appendChild(row);
        }
        
        function removeSchemaField(btn) {
            const rows = document.querySelectorAll('.schema-field-row');
            if (rows.length > 1) {
                btn.closest('.schema-field-row').remove();
            }
        }
        
        async function createSchema() {
            if (!currentSchemaApiKey) {
                alert('Please select an API key first');
                return;
            }
            
            const fields = [];
            document.querySelectorAll('.schema-field-row').forEach(row => {
                const name = row.querySelector('.schema-field-name').value.trim();
                const type = row.querySelector('.schema-field-type').value;
                if (name) {
                    fields.push({ name, type });
                }
            });
            
            if (fields.length === 0) {
                alert('Please add at least one field');
                return;
            }
            
            const aggregations = [];
            if (document.getElementById('aggHourly').checked) aggregations.push('hourly');
            if (document.getElementById('aggDaily').checked) aggregations.push('daily');
            
            if (aggregations.length === 0) {
                alert('Please select at least one aggregation type');
                return;
            }
            
            const btn = document.getElementById('createSchemaBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Creating...';
            
            try {
                const response = await fetch('/api/schema/', {
                    method: 'POST',
                    headers: {
                        'X-API-Key': currentSchemaApiKey,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ fields, aggregations })
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert('Schema created successfully! New events will now be aggregated.');
                    loadSchemaStatus(currentSchemaApiKey);
                } else {
                    alert('Error: ' + (data.message || 'Failed to create schema'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = '⚡ Create Schema & Enable Optimization';
            }
        }
        
        async function rebuildSchema() {
            if (!currentSchemaApiKey) return;
            
            if (!confirm('This will rebuild all aggregation tables from raw events. This may take a while for large datasets. Continue?')) {
                return;
            }
            
            const btn = document.getElementById('rebuildSchemaBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Rebuilding...';
            
            try {
                const response = await fetch('/api/schema/rebuild', {
                    method: 'POST',
                    headers: { 'X-API-Key': currentSchemaApiKey }
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert('Aggregations rebuilt successfully!');
                    loadSchemaStatus(currentSchemaApiKey);
                } else {
                    alert('Error: ' + (data.message || 'Failed to rebuild'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = '🔄 Rebuild Aggregations';
            }
        }
        
        async function deleteSchema() {
            if (!currentSchemaApiKey) return;
            
            if (!confirm('This will delete the schema and all aggregation tables. Raw events will be preserved. Continue?')) {
                return;
            }
            
            const btn = document.getElementById('deleteSchemaBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Deleting...';
            
            try {
                const response = await fetch('/api/schema/', {
                    method: 'DELETE',
                    headers: { 'X-API-Key': currentSchemaApiKey }
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert('Schema deleted. You can create a new one anytime.');
                    loadSchemaStatus(currentSchemaApiKey);
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = '🗑️ Delete Schema';
            }
        }
        
        // --- KV Store Functions ---
        let currentKvEditRow = null;
        
        // KV Search functionality
        const kvSearchInput = document.getElementById('kvSearchInput');
        if (kvSearchInput) {
            kvSearchInput.addEventListener('input', function(e) {
                const filterId = globalApiKeyFilter?.value || '';
                updateViewDataFilter(filterId);
            });
        }
        
        function showKvValueModal(row) {
            const modal = document.getElementById('kvValueModal');
            const key = row.dataset.key;
            const value = row.dataset.value;
            const apiKey = row.dataset.apiKey;
            const apiKeyName = row.dataset.apiKeyName;
            const created = row.dataset.created;
            const updated = row.dataset.updated;
            
            document.getElementById('kvModalKeyFull').textContent = key;
            document.getElementById('kvModalValue').textContent = value;
            document.getElementById('kvModalApiKey').textContent = apiKeyName || (apiKey.substring(0, 16) + '...');
            document.getElementById('kvModalCreated').textContent = new Date(created).toLocaleString();
            document.getElementById('kvModalUpdated').textContent = new Date(updated).toLocaleString();
            document.getElementById('kvModalSize').textContent = formatBytes(value.length);
            
            modal.classList.add('active');
        }
        
        function closeKvValueModal(event) {
            if (!event || event.target.id === 'kvValueModal' || event.target.classList.contains('modal-close') || event.target.classList.contains('btn-secondary')) {
                document.getElementById('kvValueModal').classList.remove('active');
            }
        }
        
        function openKvEditModal(row) {
            currentKvEditRow = row;
            const modal = document.getElementById('kvEditModal');
            const key = row.dataset.key;
            const value = row.dataset.value;
            
            document.getElementById('kvEditKey').value = key;
            document.getElementById('kvEditValue').value = value;
            updateKvValueSize();
            
            modal.classList.add('active');
        }
        
        function closeKvEditModal(event) {
            if (!event || event.target.id === 'kvEditModal' || event.target.classList.contains('modal-close') || event.target.textContent === 'Cancel') {
                document.getElementById('kvEditModal').classList.remove('active');
                currentKvEditRow = null;
            }
        }
        
        function updateKvValueSize() {
            const value = document.getElementById('kvEditValue').value;
            const size = formatBytes(value.length);
            document.getElementById('kvEditValueSize').textContent = size;
            
            const maxSize = <?= $maxValueSize ?>;
            const saveBtn = document.getElementById('kvSaveBtn');
            if (value.length > maxSize) {
                saveBtn.disabled = true;
                document.getElementById('kvEditValueSize').style.color = '#ef4444';
            } else {
                saveBtn.disabled = false;
                document.getElementById('kvEditValueSize').style.color = 'var(--text-muted)';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const kvEditValue = document.getElementById('kvEditValue');
            if (kvEditValue) {
                kvEditValue.addEventListener('input', updateKvValueSize);
            }
        });
        
        async function saveKvEdit() {
            if (!currentKvEditRow) return;
            
            const kvId = currentKvEditRow.dataset.id;
            const newValue = document.getElementById('kvEditValue').value;
            const saveBtn = document.getElementById('kvSaveBtn');
            
            const maxSize = <?= $maxValueSize ?>;
            const maxSizeKB = <?= $maxValueSizeKB ?>;
            if (newValue.length > maxSize) {
                alert(`Value exceeds maximum size of ${maxSizeKB} KB`);
                return;
            }
            
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
            
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('kv_action', 'update');
                formData.append('kv_id', kvId);
                formData.append('value', newValue);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    currentKvEditRow.dataset.value = newValue;
                    currentKvEditRow.dataset.updated = result.updated_at;
                    
                    const valuePreview = currentKvEditRow.querySelector('.btn-ghost');
                    if (valuePreview) valuePreview.textContent = truncateValue(newValue, 100);
                    
                    const timestampCell = currentKvEditRow.querySelector('.kv-timestamp');
                    if (timestampCell) {
                        const date = new Date(result.updated_at);
                        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        timestampCell.textContent = `${months[date.getMonth()]} ${date.getDate()} ${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
                    }
                    
                    closeKvEditModal();
                    showNotification('Value updated successfully', 'success');
                } else {
                    alert('Error: ' + result.message);
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                }
            } catch (error) {
                alert('Error updating value: ' + error.message);
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Changes';
            }
        }
        
        async function deleteKvKey(row) {
            const key = row.dataset.key;
            const kvId = row.dataset.id;
            
            if (!confirm(`Are you sure you want to delete this key?\n\nKey: ${key}\n\nThis action cannot be undone.`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('kv_action', 'delete');
                formData.append('kv_id', kvId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    row.style.transition = 'opacity 0.3s, transform 0.3s';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-20px)';
                    
                    setTimeout(() => {
                        row.remove();
                        const tbody = document.getElementById('kvTableBody');
                        if (tbody && tbody.children.length === 0) {
                            window.location.reload();
                        }
                    }, 300);
                    
                    showNotification('Key deleted successfully', 'success');
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error deleting key: ' + error.message);
            }
        }
        
        function truncateValue(value, maxLength = 100) {
            if (value.length <= maxLength) return value;
            return value.substring(0, maxLength) + '...';
        }
        
        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
            return (bytes / 1048576).toFixed(2) + ' MB';
        }
        
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'};
                color: ${type === 'success' ? '#34d399' : '#f87171'};
                border: 1px solid ${type === 'success' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)'};
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                z-index: 10000;
                backdrop-filter: blur(10px);
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // --- Export Functions ---
        function getActiveApiKeyFilter() {
            const globalFilter = document.getElementById('globalApiKeyFilter');
            return globalFilter ? globalFilter.value : null;
        }
        
        async function exportKvData() {
            const filterApiKeyId = getActiveApiKeyFilter();
            
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('export_action', 'export_kv');
                if (filterApiKeyId) {
                    formData.append('filter_api_key_id', filterApiKeyId);
                }
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    const filterNote = filterApiKeyId ? ' (filtered)' : '';
                    downloadJson(result.export, 'kv-data-export');
                    showNotification(`Exported ${result.export.total_items} key-value pairs${filterNote}`, 'success');
                } else {
                    showNotification('Export failed: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Export failed: ' + error.message, 'error');
            }
        }
        
        async function exportEvents() {
            const filterApiKeyId = getActiveApiKeyFilter();
            
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('export_action', 'export_events');
                if (filterApiKeyId) {
                    formData.append('filter_api_key_id', filterApiKeyId);
                }
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    const filterNote = filterApiKeyId ? ' (filtered)' : '';
                    downloadJson(result.export, 'events-export');
                    showNotification(`Exported ${result.export.total_items} events${filterNote}`, 'success');
                } else {
                    showNotification('Export failed: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Export failed: ' + error.message, 'error');
            }
        }
        
        function downloadJson(data, filenamePrefix) {
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
            const filename = `${filenamePrefix}-${timestamp}.json`;
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // --- Event Explorer Functions ---
        function refreshEvents() {
            window.location.reload();
        }
        
        function showEventModal(eventData) {
            const modal = document.getElementById('eventModal');
            
            document.getElementById('eventModalTimestamp').textContent = eventData.event_timestamp;
            document.getElementById('eventModalSource').textContent = eventData.key_name || eventData.api_key.substring(0, 8) + '...';
            
            try {
                const json = JSON.parse(eventData.event_data);
                document.getElementById('eventModalPayload').innerHTML = syntaxHighlight(json);
            } catch (e) {
                document.getElementById('eventModalPayload').textContent = eventData.event_data;
            }
            
            modal.classList.add('active');
        }

        function closeEventModal(e) {
            if (!e || e.target.id === 'eventModal' || e.target.classList.contains('modal-close') || e.target.classList.contains('btn-secondary')) {
                document.getElementById('eventModal').classList.remove('active');
            }
        }

        function syntaxHighlight(json) {
            if (typeof json != 'string') {
                json = JSON.stringify(json, undefined, 2);
            }
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                var cls = 'number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'key';
                    } else {
                        cls = 'string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'boolean';
                } else if (/null/.test(match)) {
                    cls = 'null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }
        
        // Event Chart Rendering
        let eventChartRendered = false;
        function renderEventChart() {
            const chartContainer = document.getElementById('event-volume-chart');
            if (!chartContainer) return;
            
            // Prevent re-rendering if already rendered
            if (eventChartRendered) return;
            eventChartRendered = true;
            
            const rawData = <?= json_encode($eventChartData) ?>;
            
            if (!rawData || rawData.length === 0) {
                chartContainer.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);">No data available for chart</div>';
                return;
            }
            
            const margin = {top: 20, right: 20, bottom: 30, left: 40};
            const width = chartContainer.clientWidth - margin.left - margin.right;
            const height = chartContainer.clientHeight - margin.top - margin.bottom;

            const svg = d3.select("#event-volume-chart")
                .append("svg")
                .attr("width", width + margin.left + margin.right)
                .attr("height", height + margin.top + margin.bottom)
                .append("g")
                .attr("transform", `translate(${margin.left},${margin.top})`);

            const parseDate = d3.timeParse("%Y-%m-%d");
            const formatDate = d3.timeFormat("%b %d");
            
            const data = rawData.map(d => ({
                date: parseDate(d.date),
                value: +d.count
            }));

            const x = d3.scaleTime()
                .domain(d3.extent(data, d => d.date))
                .range([0, width]);

            const y = d3.scaleLinear()
                .domain([0, d3.max(data, d => d.value) * 1.1])
                .range([height, 0]);

            const gradient = svg.append("defs")
                .append("linearGradient")
                .attr("id", "eventGradient")
                .attr("x1", "0%")
                .attr("y1", "0%")
                .attr("x2", "0%")
                .attr("y2", "100%");

            gradient.append("stop")
                .attr("offset", "0%")
                .attr("stop-color", "#10b981")
                .attr("stop-opacity", 0.5);

            gradient.append("stop")
                .attr("offset", "100%")
                .attr("stop-color", "#10b981")
                .attr("stop-opacity", 0);

            svg.append("g")
                .attr("transform", `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(5).tickFormat(formatDate))
                .attr("color", "#64748b")
                .select(".domain").remove();

            svg.append("g")
                .call(d3.axisLeft(y).ticks(5))
                .attr("color", "#64748b")
                .select(".domain").remove();

            svg.append("g")
                .attr("class", "grid")
                .attr("stroke", "rgba(255, 255, 255, 0.05)")
                .attr("stroke-dasharray", "3,3")
                .call(d3.axisLeft(y).ticks(5).tickSize(-width).tickFormat(""))
                .select(".domain").remove();

            svg.append("path")
                .datum(data)
                .attr("fill", "url(#eventGradient)")
                .attr("stroke", "#10b981")
                .attr("stroke-width", 2)
                .attr("d", d3.area()
                    .x(d => x(d.date))
                    .y0(height)
                    .y1(d => y(d.value))
                    .curve(d3.curveMonotoneX)
                );
                
            const tooltip = d3.select("body").append("div")
                .attr("class", "chart-tooltip")
                .style("opacity", 0)
                .style("position", "absolute")
                .style("background", "rgba(15, 23, 42, 0.9)")
                .style("border", "1px solid rgba(148, 163, 184, 0.2)")
                .style("padding", "8px 12px")
                .style("border-radius", "6px")
                .style("pointer-events", "none")
                .style("font-size", "0.8rem")
                .style("box-shadow", "0 4px 6px -1px rgba(0, 0, 0, 0.1)")
                .style("z-index", "10");
                
            svg.selectAll("dot")
                .data(data)
                .enter()
                .append("circle")
                .attr("cx", d => x(d.date))
                .attr("cy", d => y(d.value))
                .attr("r", 4)
                .attr("fill", "#1e293b")
                .attr("stroke", "#10b981")
                .attr("stroke-width", 2)
                .on("mouseover", function(event, d) {
                    d3.select(this).attr("r", 6).attr("fill", "#10b981");
                    tooltip.transition().duration(200).style("opacity", 1);
                    tooltip.html(`<strong>${formatDate(d.date)}</strong><br/>Events: ${d.value}`)
                        .style("left", (event.pageX + 10) + "px")
                        .style("top", (event.pageY - 28) + "px");
                })
                .on("mouseout", function(d) {
                    d3.select(this).attr("r", 4).attr("fill", "#1e293b");
                    tooltip.transition().duration(500).style("opacity", 0);
                });
        }
        
        // ============================================
        // API Playground Functions
        // ============================================
        
        const PLAYGROUND_API_BASE = <?= json_encode($apiBaseUrl) ?>;
        let playgroundResponseData = null;
        
        function updatePlaygroundEndpoint() {
            const select = document.getElementById('playgroundEndpoint');
            const option = select.options[select.selectedIndex];
            const method = option.dataset.method || 'POST';
            const body = option.dataset.body || '';
            const isDestructive = option.dataset.destructive === 'true';
            
            // Update method badge
            const methodEl = document.getElementById('playgroundMethod');
            methodEl.textContent = method;
            methodEl.className = 'playground-method method-' + method.toLowerCase();
            
            // Update body textarea
            const bodyGroup = document.getElementById('playgroundBodyGroup');
            const bodyTextarea = document.getElementById('playgroundBody');
            
            if (method === 'GET' || body === '') {
                bodyGroup.style.display = 'none';
                bodyTextarea.value = '';
            } else {
                bodyGroup.style.display = 'block';
                try {
                    // Format JSON nicely
                    const parsed = JSON.parse(body);
                    bodyTextarea.value = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    bodyTextarea.value = body;
                }
            }
            
            // Clear any previous errors
            document.getElementById('playgroundBodyError').classList.remove('visible');
            document.getElementById('playgroundBodyError').textContent = '';
            
            // Warn for destructive operations
            const sendBtn = document.getElementById('playgroundSendBtn');
            if (isDestructive) {
                sendBtn.classList.add('btn-danger');
                sendBtn.classList.remove('btn-primary');
                sendBtn.innerHTML = '<span class="btn-icon-send">⚠️</span><span>Send (Destructive)</span>';
            } else {
                sendBtn.classList.remove('btn-danger');
                sendBtn.classList.add('btn-primary');
                sendBtn.innerHTML = '<span class="btn-icon-send">🚀</span><span>Send Request</span>';
            }
        }
        
        function formatPlaygroundJson() {
            const bodyTextarea = document.getElementById('playgroundBody');
            const errorEl = document.getElementById('playgroundBodyError');
            
            try {
                const parsed = JSON.parse(bodyTextarea.value);
                bodyTextarea.value = JSON.stringify(parsed, null, 2);
                errorEl.classList.remove('visible');
            } catch (e) {
                errorEl.textContent = 'Invalid JSON: ' + e.message;
                errorEl.classList.add('visible');
            }
        }
        
        async function sendPlaygroundRequest() {
            const apiKey = document.getElementById('playgroundApiKey').value;
            const endpointSelect = document.getElementById('playgroundEndpoint');
            const option = endpointSelect.options[endpointSelect.selectedIndex];
            const method = option.dataset.method || 'POST';
            const isDestructive = option.dataset.destructive === 'true';
            let endpoint = endpointSelect.value;
            
            // Handle special endpoints like 'set-update' -> 'set'
            if (endpoint === 'set-update') endpoint = 'set';
            if (endpoint === 'event/push-batch') endpoint = 'event/push';
            if (endpoint === 'event/push-timestamp') endpoint = 'event/push';
            if (endpoint === 'schema-get') endpoint = 'schema';
            if (endpoint === 'schema-delete') endpoint = 'schema';
            
            const bodyTextarea = document.getElementById('playgroundBody');
            const errorEl = document.getElementById('playgroundBodyError');
            const sendBtn = document.getElementById('playgroundSendBtn');
            
            // Validate API key
            if (!apiKey) {
                alert('Please select an API key first.');
                return;
            }
            
            // Confirm destructive operations
            if (isDestructive) {
                if (!confirm('⚠️ This is a destructive operation that cannot be undone. Are you sure you want to proceed?')) {
                    return;
                }
            }
            
            // Validate JSON body for POST requests
            let requestBody = null;
            if (method === 'POST' && bodyTextarea.value.trim()) {
                try {
                    requestBody = JSON.parse(bodyTextarea.value);
                    errorEl.classList.remove('visible');
                } catch (e) {
                    errorEl.textContent = 'Invalid JSON: ' + e.message;
                    errorEl.classList.add('visible');
                    return;
                }
            }
            
            // Update UI to loading state
            sendBtn.classList.add('loading');
            sendBtn.innerHTML = '<span class="btn-icon-send">⏳</span><span>Sending...</span>';
            
            const startTime = performance.now();
            
            try {
                const fetchOptions = {
                    method: method,
                    headers: {
                        'X-API-Key': apiKey,
                    }
                };
                
                if (requestBody) {
                    fetchOptions.headers['Content-Type'] = 'application/json';
                    fetchOptions.body = JSON.stringify(requestBody);
                }
                
                const url = PLAYGROUND_API_BASE + '/' + endpoint;
                const response = await fetch(url, fetchOptions);
                const endTime = performance.now();
                const duration = Math.round(endTime - startTime);
                
                // Collect response headers
                const headers = {};
                response.headers.forEach((value, key) => {
                    headers[key] = value;
                });
                
                // Get response text
                const responseText = await response.text();
                let responseJson = null;
                
                try {
                    responseJson = JSON.parse(responseText);
                } catch (e) {
                    // Not JSON, keep as text
                }
                
                // Store response data
                playgroundResponseData = {
                    status: response.status,
                    statusText: response.statusText,
                    headers: headers,
                    body: responseJson || responseText,
                    raw: responseText,
                    duration: duration
                };
                
                // Update UI
                displayPlaygroundResponse(playgroundResponseData);
                
            } catch (error) {
                playgroundResponseData = {
                    status: 0,
                    statusText: 'Network Error',
                    headers: {},
                    body: { error: error.message },
                    raw: error.message,
                    duration: Math.round(performance.now() - startTime)
                };
                displayPlaygroundResponse(playgroundResponseData);
            } finally {
                // Reset button state
                sendBtn.classList.remove('loading');
                const isCurrentDestructive = option.dataset.destructive === 'true';
                if (isCurrentDestructive) {
                    sendBtn.innerHTML = '<span class="btn-icon-send">⚠️</span><span>Send (Destructive)</span>';
                } else {
                    sendBtn.innerHTML = '<span class="btn-icon-send">🚀</span><span>Send Request</span>';
                }
            }
        }
        
        function displayPlaygroundResponse(data) {
            // Update status badge
            const statusEl = document.getElementById('playgroundStatus');
            statusEl.textContent = data.status + ' ' + data.statusText;
            statusEl.className = 'playground-status';
            
            if (data.status >= 200 && data.status < 300) {
                statusEl.classList.add('status-success');
            } else if (data.status >= 300 && data.status < 400) {
                statusEl.classList.add('status-redirect');
            } else {
                statusEl.classList.add('status-error');
            }
            
            // Update timing
            document.getElementById('playgroundTime').textContent = data.duration + 'ms';
            
            // Update body content with syntax highlighting
            const bodyEl = document.getElementById('playgroundResponseBody');
            if (typeof data.body === 'object') {
                bodyEl.innerHTML = syntaxHighlightJson(JSON.stringify(data.body, null, 2));
            } else {
                bodyEl.textContent = data.body;
            }
            
            // Update headers content
            const headersEl = document.getElementById('playgroundResponseHeaders');
            if (Object.keys(data.headers).length > 0) {
                let headersHtml = '';
                for (const [key, value] of Object.entries(data.headers)) {
                    headersHtml += '<div class="response-header-row">';
                    headersHtml += '<span class="response-header-name">' + escapeHtml(key) + '</span>';
                    headersHtml += '<span class="response-header-value">' + escapeHtml(value) + '</span>';
                    headersHtml += '</div>';
                }
                headersEl.innerHTML = headersHtml;
            } else {
                headersEl.innerHTML = '<div class="playground-empty-response"><span class="playground-empty-icon">📋</span><p>No headers available</p></div>';
            }
            
            // Update raw content
            const rawEl = document.getElementById('playgroundResponseRaw');
            rawEl.textContent = data.raw;
            
            // Show response actions
            document.getElementById('playgroundResponseActions').style.display = 'flex';
            
            // Refresh stats cards and fetch new joke
            refreshStatsCards();
            fetchDadJoke();
        }
        
        function syntaxHighlightJson(json) {
            // Escape HTML first
            json = escapeHtml(json);
            
            // Apply syntax highlighting
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(match) {
                let cls = 'json-number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'json-key';
                    } else {
                        cls = 'json-string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'json-boolean';
                } else if (/null/.test(match)) {
                    cls = 'json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function switchResponseTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.playground-tab').forEach(tab => {
                tab.classList.remove('active');
                if (tab.dataset.tab === tabName) {
                    tab.classList.add('active');
                }
            });
            
            // Update content panels
            document.querySelectorAll('.playground-response-content').forEach(content => {
                content.classList.remove('active');
            });
            
            const targetContent = document.getElementById('playgroundResponse' + tabName.charAt(0).toUpperCase() + tabName.slice(1));
            if (targetContent) {
                targetContent.classList.add('active');
            }
        }
        
        function copyPlaygroundResponse() {
            if (!playgroundResponseData) return;
            
            const textToCopy = typeof playgroundResponseData.body === 'object' 
                ? JSON.stringify(playgroundResponseData.body, null, 2) 
                : playgroundResponseData.raw;
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Show brief feedback
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '✓ Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 1500);
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }
        
        async function refreshStatsCards() {
            try {
                const response = await fetch('/admin/?ajax_stats=1');
                const data = await response.json();
                
                if (data.status === 'success' && data.stats) {
                    const s = data.stats;
                    
                    // Update main stats
                    const apiKeysEl = document.getElementById('stat-api-keys');
                    if (apiKeysEl) apiKeysEl.innerHTML = `${s.api_key_count} <span style="font-size: 1rem; color: var(--text-muted); font-weight: 400;">/ ${s.max_keys}</span>`;
                    
                    const storedKeysEl = document.getElementById('stat-stored-keys');
                    if (storedKeysEl) storedKeysEl.textContent = Number(s.stored_keys).toLocaleString();
                    
                    const totalRequestsEl = document.getElementById('stat-total-requests');
                    if (totalRequestsEl) totalRequestsEl.textContent = Number(s.total_requests).toLocaleString();
                    
                    const payloadEl = document.getElementById('stat-payload');
                    if (payloadEl) payloadEl.textContent = s.payload_data;
                    
                    const totalStorageEl = document.getElementById('stat-total-storage');
                    if (totalStorageEl) totalStorageEl.textContent = s.total_storage;
                    
                    // Update event stats (both top card and events tab)
                    const eventTotalTopEl = document.getElementById('stat-event-total-top');
                    if (eventTotalTopEl) eventTotalTopEl.textContent = Number(s.event_total).toLocaleString();
                    
                    const eventTotalEl = document.getElementById('stat-event-total');
                    if (eventTotalEl) eventTotalEl.textContent = Number(s.event_total).toLocaleString();
                    
                    const eventTodayEl = document.getElementById('stat-event-today');
                    if (eventTodayEl) eventTodayEl.textContent = Number(s.event_today).toLocaleString();
                    
                    const eventSourcesEl = document.getElementById('stat-event-sources');
                    if (eventSourcesEl) eventSourcesEl.textContent = Number(s.event_sources).toLocaleString();
                    
                    // Add a brief highlight animation
                    document.querySelectorAll('.stat-value').forEach(el => {
                        el.style.transition = 'color 0.3s';
                        el.style.color = '#34d399';
                        setTimeout(() => {
                            el.style.color = '';
                        }, 500);
                    });
                }
            } catch (error) {
                console.error('Failed to refresh stats:', error);
            }
        }
        
        function resetPlayground() {
            // Reset to first endpoint
            const endpointSelect = document.getElementById('playgroundEndpoint');
            endpointSelect.selectedIndex = 0;
            updatePlaygroundEndpoint();
            
            // Clear response
            playgroundResponseData = null;
            document.getElementById('playgroundStatus').textContent = '—';
            document.getElementById('playgroundStatus').className = 'playground-status';
            document.getElementById('playgroundTime').textContent = '—';
            document.getElementById('playgroundResponseBody').innerHTML = '<div class="playground-empty-response"><span class="playground-empty-icon">📡</span><p>Send a request to see the response</p></div>';
            document.getElementById('playgroundResponseHeaders').innerHTML = '<div class="playground-empty-response"><span class="playground-empty-icon">📋</span><p>No headers yet</p></div>';
            document.getElementById('playgroundResponseRaw').innerHTML = '<div class="playground-empty-response"><span class="playground-empty-icon">📄</span><p>No raw response yet</p></div>';
            document.getElementById('playgroundResponseActions').style.display = 'none';
            
            // Reset to body tab
            switchResponseTab('body');
        }
        
        // Initialize playground on page load
        document.addEventListener('DOMContentLoaded', function() {
            updatePlaygroundEndpoint();
        });
        
        // Fetch a random dad joke for the playground default value
        // Proxied through PHP to avoid CORS issues - API docs: https://icanhazdadjoke.com/api
        async function fetchDadJoke() {
            let joke = 'Why do programmers prefer dark mode? Because light attracts bugs!';
            
            try {
                const response = await fetch('/admin/?ajax_joke=1');
                if (response.ok) {
                    const data = await response.json();
                    joke = data.joke || joke;
                }
            } catch (error) {
                console.error('Failed to fetch joke:', error);
                // Use fallback joke
            }
            
            // Always update the data-body attribute on the set option
            const setOption = document.querySelector('#playgroundEndpoint option[value="set"][data-joke="true"]');
            if (setOption) {
                setOption.dataset.body = JSON.stringify({ value: joke });
            }
            
            // Always update textarea if on the joke endpoint
            const endpointSelect = document.getElementById('playgroundEndpoint');
            const bodyTextarea = document.getElementById('playgroundBody');
            if (endpointSelect && bodyTextarea) {
                const currentOption = endpointSelect.options[endpointSelect.selectedIndex];
                if (currentOption && currentOption.dataset.joke === 'true') {
                    bodyTextarea.value = JSON.stringify({ value: joke }, null, 2);
                }
            }
        }
        
        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeKvValueModal();
                closeKvEditModal();
                closeEventModal();
            }
        });
        
        // --- Request Logs Functions ---
        function applyLogFilters() {
            const params = new URLSearchParams(window.location.search);
            
            // Get filter values
            const statusFilter = document.getElementById('logStatusFilter')?.value || '';
            const endpointFilter = document.getElementById('logEndpointFilter')?.value || '';
            const methodFilter = document.getElementById('logMethodFilter')?.value || '';
            const apiKeyFilter = document.getElementById('logApiKeyFilter')?.value || '';
            const dateFrom = document.getElementById('logDateFrom')?.value || '';
            const dateTo = document.getElementById('logDateTo')?.value || '';
            
            // Clear existing log filters
            params.delete('log_status');
            params.delete('log_endpoint');
            params.delete('log_method');
            params.delete('log_key');
            params.delete('log_from');
            params.delete('log_to');
            params.delete('log_page');
            
            // Set new filter values
            if (statusFilter) params.set('log_status', statusFilter);
            if (endpointFilter) params.set('log_endpoint', endpointFilter);
            if (methodFilter) params.set('log_method', methodFilter);
            if (apiKeyFilter) params.set('log_key', apiKeyFilter);
            if (dateFrom) params.set('log_from', dateFrom);
            if (dateTo) params.set('log_to', dateTo);
            
            // Navigate to filtered URL
            window.location.search = params.toString();
        }
        
        function clearLogFilters() {
            const params = new URLSearchParams(window.location.search);
            
            // Remove all log filters
            params.delete('log_status');
            params.delete('log_endpoint');
            params.delete('log_method');
            params.delete('log_key');
            params.delete('log_from');
            params.delete('log_to');
            params.delete('log_page');
            
            // Navigate to URL without filters
            const newSearch = params.toString();
            window.location.search = newSearch;
        }
        
        function goToLogPage(page) {
            const params = new URLSearchParams(window.location.search);
            params.set('log_page', page);
            window.location.search = params.toString();
        }
        
        // Auto-apply filters on Enter key
        document.addEventListener('DOMContentLoaded', function() {
            const logFilterInputs = document.querySelectorAll('.logs-filters select, .logs-filters input[type="date"]');
            logFilterInputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        applyLogFilters();
                    }
                });
            });
            
            // Quick filter shortcuts - click on status cards
            document.querySelectorAll('#logs .stat-card').forEach((card, index) => {
                card.style.cursor = 'pointer';
                card.addEventListener('click', function() {
                    const statusSelect = document.getElementById('logStatusFilter');
                    if (!statusSelect) return;
                    
                    // Map card index to filter value
                    const filterMap = ['', 'success', 'client_error', 'server_error'];
                    if (filterMap[index] !== undefined) {
                        statusSelect.value = filterMap[index];
                        applyLogFilters();
                    }
                });
            });
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