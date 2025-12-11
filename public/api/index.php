<?php

declare(strict_types=1);

/**
 * Key/Value Store API
 * 
 * RESTful API for storing and retrieving key-value pairs.
 * Features UUID-based keys, atomic operations, and isolated namespaces per API key.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

// Security headers
addSecurityHeaders();

// Load configuration
$config = loadConfig();

// Enforce HTTPS in production
if (isProduction($config)) {
    enforceHttps();
}

// Parse endpoint path early to route to sub-APIs
$path = parseEndpointPath('/api/');

// Route event/* paths to event.php (handles its own initialization)
if (str_starts_with($path, 'event/') || $path === 'event') {
    require __DIR__ . '/event.php';
    exit;
}

// Route schema/* paths to schema.php (handles its own initialization)
if (str_starts_with($path, 'schema/') || $path === 'schema') {
    require __DIR__ . '/schema.php';
    exit;
}

try {
    $pdo = getDatabaseConnection($config);

    // Initialize request with standard security checks
    ['apiKeyId' => $apiKeyId] = initializeApiRequest($config, $pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    // Route to handler
    match (true) {
        $method === 'POST' && $path === 'get' => handleGet($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'set' => handleSet($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'delete' => handleDelete($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'exists' => handleExists($pdo, $config, $apiKeyId),
        $method === 'GET' && $path === 'list' => handleList($pdo, $config, $apiKeyId),
        $method === 'DELETE' && $path === 'clear' => handleClear($pdo, $config, $apiKeyId),
        default => sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'Endpoint not found'
        ])
    };
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " - File: " . $e->getFile() . ":" . $e->getLine());

    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}

/**
 * GET endpoint - Retrieve a value by key.
 */
function handleGet(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $maxSize = getMaxValueSizeBytes($config);
    $input = parseJsonBody($maxSize);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'get', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON']);
    }

    if (!isset($input['key'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'get', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Missing required field: key']);
    }

    $key = validateUuidKey($input['key']);
    if ($key === null) {
        sendKeyValidationError($pdo, $config, $apiKeyId, 'get');
    }

    $stmt = $pdo->prepare("
        SELECT value FROM {$prefix}kv_store 
        WHERE api_key_id = ? AND `key` = ?
    ");
    $stmt->execute([$apiKeyId, $key]);
    $result = $stmt->fetch();

    if ($result) {
        logApiRequest($pdo, $config, $apiKeyId, 'get', 'POST', 200);
        sendJsonResponse(200, [
            'status' => 'success',
            'key' => $key,
            'value' => $result['value']
        ]);
    }

    logApiRequest($pdo, $config, $apiKeyId, 'get', 'POST', 404);
    sendJsonResponse(404, ['status' => 'error', 'message' => 'Key not found']);
}

/**
 * SET endpoint - Store or update a value.
 * If no key is provided, generates a new UUID v4 key.
 */
function handleSet(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $maxSize = getMaxValueSizeBytes($config);
    $input = parseJsonBody($maxSize);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'set', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON or payload too large']);
    }

    if (!isset($input['value'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'set', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Missing required field: value']);
    }

    if (!is_string($input['value'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'set', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Value must be a string']);
    }

    $value = $input['value'];
    $valueBytes = strlen($value);

    if ($valueBytes > $maxSize) {
        logApiRequest($pdo, $config, $apiKeyId, 'set', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => "Value exceeds maximum size of {$maxSize} bytes"]);
    }

    // Update existing key or create new one
    $isNewKey = false;
    $oldValueBytes = 0;
    
    if (isset($input['key'])) {
        $key = validateUuidKey($input['key']);
        if ($key === null) {
            sendKeyValidationError($pdo, $config, $apiKeyId, 'set');
        }

        // Check if key exists and get old value size for stats update
        $checkStmt = $pdo->prepare("SELECT LENGTH(value) as size FROM {$prefix}kv_store WHERE api_key_id = ? AND `key` = ?");
        $checkStmt->execute([$apiKeyId, $key]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            $oldValueBytes = (int)$existing['size'];
        } else {
            $isNewKey = true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}kv_store (api_key_id, `key`, value) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = ?, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$apiKeyId, $key, $value, $value]);
    } else {
        $key = generateUuidV4();
        $isNewKey = true;

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}kv_store (api_key_id, `key`, value) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$apiKeyId, $key, $value]);
    }
    
    // Update API key stats (non-blocking, best effort)
    try {
        $bytesDelta = $valueBytes - $oldValueBytes;
        $kvDelta = $isNewKey ? 1 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}api_key_stats (api_key_id, total_kv_pairs, total_kv_bytes)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                total_kv_pairs = total_kv_pairs + ?,
                total_kv_bytes = total_kv_bytes + ?
        ");
        $stmt->execute([$apiKeyId, $kvDelta, $valueBytes, $kvDelta, $bytesDelta]);
    } catch (Exception $e) {
        // Stats table might not exist, silently ignore
    }

    logApiRequest($pdo, $config, $apiKeyId, 'set', 'POST', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Value stored successfully',
        'key' => $key
    ]);
}

/**
 * DELETE endpoint - Remove a key-value pair.
 */
function handleDelete(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $maxSize = getMaxValueSizeBytes($config);
    $input = parseJsonBody($maxSize);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'delete', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON']);
    }

    if (!isset($input['key'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'delete', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Missing required field: key']);
    }

    $key = validateUuidKey($input['key']);
    if ($key === null) {
        sendKeyValidationError($pdo, $config, $apiKeyId, 'delete');
    }

    // Get value size before deleting for stats update
    $checkStmt = $pdo->prepare("SELECT LENGTH(value) as size FROM {$prefix}kv_store WHERE api_key_id = ? AND `key` = ?");
    $checkStmt->execute([$apiKeyId, $key]);
    $existing = $checkStmt->fetch();
    $deletedBytes = $existing ? (int)$existing['size'] : 0;

    $stmt = $pdo->prepare("
        DELETE FROM {$prefix}kv_store 
        WHERE api_key_id = ? AND `key` = ?
    ");
    $stmt->execute([$apiKeyId, $key]);

    if ($stmt->rowCount() > 0) {
        // Update API key stats (non-blocking, best effort)
        try {
            $stmt = $pdo->prepare("
                UPDATE {$prefix}api_key_stats 
                SET total_kv_pairs = GREATEST(0, total_kv_pairs - 1),
                    total_kv_bytes = GREATEST(0, total_kv_bytes - ?)
                WHERE api_key_id = ?
            ");
            $stmt->execute([$deletedBytes, $apiKeyId]);
        } catch (Exception $e) {
            // Stats table might not exist, silently ignore
        }
        
        logApiRequest($pdo, $config, $apiKeyId, 'delete', 'POST', 200);
        sendJsonResponse(200, [
            'status' => 'success',
            'message' => 'Key deleted successfully',
            'key' => $key
        ]);
    }

    logApiRequest($pdo, $config, $apiKeyId, 'delete', 'POST', 404);
    sendJsonResponse(404, ['status' => 'error', 'message' => 'Key not found']);
}

/**
 * EXISTS endpoint - Check if a key exists.
 */
function handleExists(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $maxSize = getMaxValueSizeBytes($config);
    $input = parseJsonBody($maxSize);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'exists', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON']);
    }

    if (!isset($input['key'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'exists', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Missing required field: key']);
    }

    $key = validateUuidKey($input['key']);
    if ($key === null) {
        sendKeyValidationError($pdo, $config, $apiKeyId, 'exists');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM {$prefix}kv_store 
        WHERE api_key_id = ? AND `key` = ?
    ");
    $stmt->execute([$apiKeyId, $key]);
    $result = $stmt->fetch();

    logApiRequest($pdo, $config, $apiKeyId, 'exists', 'POST', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'key' => $key,
        'exists' => $result['count'] > 0
    ]);
}

/**
 * LIST endpoint - List keys for the authenticated API key with pagination.
 * 
 * Query parameters:
 * - limit: Number of keys to return (1-1000, default 100)
 * - offset: Starting position (default 0)
 * - cursor: Key to start after (alternative to offset for better performance)
 */
function handleList(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    
    // Parse pagination parameters
    $limit = isset($_GET['limit']) ? min(1000, max(1, (int)$_GET['limit'])) : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $cursor = isset($_GET['cursor']) ? trim($_GET['cursor']) : null;
    
    // Use cursor-based pagination if cursor is provided (more efficient for large datasets)
    if ($cursor !== null && $cursor !== '') {
        $stmt = $pdo->prepare("
            SELECT `key`, created_at, updated_at 
            FROM {$prefix}kv_store 
            WHERE api_key_id = ? AND `key` > ?
            ORDER BY `key`
            LIMIT ?
        ");
        $stmt->execute([$apiKeyId, $cursor, $limit + 1]); // Fetch one extra to check if there are more
    } else {
        $stmt = $pdo->prepare("
            SELECT `key`, created_at, updated_at 
            FROM {$prefix}kv_store 
            WHERE api_key_id = ?
            ORDER BY `key`
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$apiKeyId, $limit + 1, $offset]); // Fetch one extra to check if there are more
    }
    
    $keys = $stmt->fetchAll();
    $hasMore = count($keys) > $limit;
    
    if ($hasMore) {
        array_pop($keys); // Remove the extra row
    }
    
    // Get total count (cached query - use for informational purposes)
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM {$prefix}kv_store WHERE api_key_id = ?
    ");
    $countStmt->execute([$apiKeyId]);
    $totalCount = (int)$countStmt->fetch()['total'];
    
    // Determine next cursor for cursor-based pagination
    $nextCursor = $hasMore && !empty($keys) ? $keys[count($keys) - 1]['key'] : null;

    logApiRequest($pdo, $config, $apiKeyId, 'list', 'GET', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'total' => $totalCount,
        'count' => count($keys),
        'limit' => $limit,
        'offset' => $cursor === null ? $offset : null,
        'has_more' => $hasMore,
        'next_cursor' => $nextCursor,
        'keys' => $keys
    ]);
}

/**
 * CLEAR endpoint - Delete all key-value pairs for the authenticated API key.
 */
function handleClear(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);

    $stmt = $pdo->prepare("DELETE FROM {$prefix}kv_store WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);
    $deletedCount = $stmt->rowCount();
    
    // Reset KV stats (non-blocking, best effort)
    try {
        $stmt = $pdo->prepare("
            UPDATE {$prefix}api_key_stats 
            SET total_kv_pairs = 0, total_kv_bytes = 0
            WHERE api_key_id = ?
        ");
        $stmt->execute([$apiKeyId]);
    } catch (Exception $e) {
        // Stats table might not exist, silently ignore
    }

    logApiRequest($pdo, $config, $apiKeyId, 'clear', 'DELETE', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'All keys cleared successfully',
        'deleted_count' => $deletedCount
    ]);
}

