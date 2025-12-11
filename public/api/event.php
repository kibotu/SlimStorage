<?php

declare(strict_types=1);

/**
 * Event Data API
 * 
 * Time-series event storage and retrieval API.
 * Perfect for IoT, analytics, monitoring, and telemetry data.
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

try {
    $pdo = getDatabaseConnection($config);

    // Initialize request with standard security checks
    ['apiKeyId' => $apiKeyId] = initializeApiRequest($config, $pdo);

    // Parse endpoint path
    $path = parseEndpointPath('/api/event/');
    $method = $_SERVER['REQUEST_METHOD'];

    // Route to handler
    match (true) {
        $method === 'POST' && $path === 'push' => handleEventPush($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'query' => handleEventQuery($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'aggregate' => handleEventAggregate($pdo, $config, $apiKeyId),
        $method === 'GET' && $path === 'stats' => handleEventStats($pdo, $config, $apiKeyId),
        $method === 'DELETE' && $path === 'clear' => handleEventClear($pdo, $config, $apiKeyId),
        default => sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'Endpoint not found'
        ])
    };
} catch (Exception $e) {
    error_log("Event API Error: " . $e->getMessage() . " - File: " . $e->getFile() . ":" . $e->getLine());

    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}

/**
 * PUSH endpoint - Store one or more events.
 * 
 * Supports single event: {"data": {...}, "timestamp": "2024-12-04T10:30:00Z"}
 * Or batch mode: {"events": [{"data": {...}, "timestamp": "..."}, ...]}
 * 
 * The "timestamp" field is optional. If provided, must be a valid ISO 8601 format.
 * If omitted, the current server time is used.
 */
function handleEventPush(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $input = parseJsonBody(API_MAX_PAYLOAD_SIZE);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'Invalid JSON or payload too large (max 1MB)'
        ]);
    }

    // Support both single event and batch modes
    $events = extractEvents($input);

    if ($events === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'Missing required field: data or events'
        ]);
    }

    $eventCount = count($events);

    if ($eventCount === 0) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'No events provided']);
    }

    if ($eventCount > API_MAX_BATCH_SIZE) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'Too many events (max ' . API_MAX_BATCH_SIZE . ' per request)'
        ]);
    }

    // Validate and prepare events
    $insertData = prepareEventsForInsert($events, $pdo, $config, $apiKeyId);

    // Batch insert with multi-value INSERT for better performance
    $pdo->beginTransaction();
    try {
        $insertedCount = batchInsertEvents($pdo, $prefix, $apiKeyId, $insertData);
        
        // Update daily stats (async-friendly, uses UPSERT)
        updateEventStats($pdo, $prefix, $apiKeyId, $insertData);
        
        // Update event key statistics for common keys tracking
        updateEventKeyStats($pdo, $prefix, $apiKeyId, $insertData);
        
        // Update schema-based aggregations if schema is defined
        updateSchemaAggregations($pdo, $prefix, $apiKeyId, $insertData);
        
        $pdo->commit();

        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 200);
        sendJsonResponse(200, [
            'status' => 'success',
            'message' => 'Events stored successfully',
            'count' => $insertedCount
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Extract events array from input (supports single and batch modes).
 */
function extractEvents(array $input): ?array
{
    if (isset($input['events']) && is_array($input['events'])) {
        return $input['events'];
    }

    if (isset($input['data'])) {
        return [$input];
    }

    return null;
}

/**
 * Validate and prepare events for database insertion.
 */
function prepareEventsForInsert(array $events, PDO $pdo, array $config, int $apiKeyId): array
{
    $insertData = [];
    $now = new DateTime();
    $maxSize = getMaxValueSizeBytes($config);

    foreach ($events as $index => $event) {
        // Validate data field exists
        if (!isset($event['data'])) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Missing 'data' field in event at index $index"
            ]);
        }

        // Validate data is a JSON object or array
        if (!is_array($event['data']) && !is_object($event['data'])) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Event data must be a JSON object or array at index $index"
            ]);
        }

        // Parse timestamp (optional)
        $timestamp = parseEventTimestamp($event, $now, $pdo, $config, $apiKeyId, $index);

        // Encode data as JSON
        $jsonData = json_encode($event['data']);
        if ($jsonData === false) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Failed to encode event data at index $index"
            ]);
        }

        // Validate size
        if (strlen($jsonData) > $maxSize) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Event data exceeds maximum size of {$maxSize} bytes at index $index"
            ]);
        }

        $insertData[] = [
            'data' => $jsonData,
            'timestamp' => $timestamp->format('Y-m-d H:i:s.v')
        ];
    }

    return $insertData;
}

/**
 * Parse and validate event timestamp.
 */
function parseEventTimestamp(
    array $event,
    DateTime $default,
    PDO $pdo,
    array $config,
    int $apiKeyId,
    int $index
): DateTime {
    if (!isset($event['timestamp'])) {
        return $default;
    }

    try {
        return new DateTime($event['timestamp']);
    } catch (Exception $e) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/push', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => "Invalid timestamp format at index $index. Use ISO 8601 format (e.g., '2024-12-04T10:30:00Z')"
        ]);
    }
}

/**
 * QUERY endpoint - Retrieve events with optional date range filtering.
 * 
 * Supports two pagination modes:
 * 1. Offset-based (default): Uses offset parameter, includes total count
 * 2. Cursor-based (recommended for large datasets): Uses cursor parameter, skips count
 * 
 * Body parameters:
 * - limit: Number of events to return (1-10000, default 1000)
 * - offset: Starting position for offset-based pagination (default 0)
 * - cursor: Event ID to start after (for cursor-based pagination, more efficient)
 * - order: 'asc' or 'desc' (default 'desc')
 * - start_date: ISO 8601 timestamp filter
 * - end_date: ISO 8601 timestamp filter
 * - skip_count: boolean - skip total count query for faster response (default false)
 */
function handleEventQuery(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $maxSize = getMaxValueSizeBytes($config);
    $input = parseJsonBody($maxSize);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON']);
    }

    // Parse query parameters
    $limit = isset($input['limit']) ? (int)$input['limit'] : 1000;
    $offset = isset($input['offset']) ? (int)$input['offset'] : 0;
    $cursor = $input['cursor'] ?? null; // Event ID to start after
    $order = (isset($input['order']) && strtolower($input['order']) === 'asc') ? 'ASC' : 'DESC';
    $skipCount = isset($input['skip_count']) && $input['skip_count'] === true;

    // Validate parameters
    if ($limit < 1 || $limit > API_MAX_QUERY_LIMIT) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'Limit must be between 1 and ' . API_MAX_QUERY_LIMIT
        ]);
    }

    if ($offset < 0) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Offset must be non-negative']);
    }

    // Build query with date filters
    [$whereClause, $params] = buildEventQueryConditions($input, $apiKeyId, $pdo, $config);

    // Get total count (skip if cursor-based or explicitly requested)
    $totalCount = null;
    if (!$skipCount && $cursor === null) {
        // Use fast count from stats table if no date filters
        if (!isset($input['start_date']) && !isset($input['end_date'])) {
            $totalCount = getEventCountFast($pdo, $prefix, $apiKeyId);
        } else {
            // Must use COUNT for filtered queries
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as total FROM {$prefix}events WHERE $whereClause
            ");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetch()['total'];
        }
    }

    // Build query based on pagination mode
    if ($cursor !== null) {
        // Cursor-based pagination (more efficient for large datasets)
        $cursorOp = $order === 'DESC' ? '<' : '>';
        $stmt = $pdo->prepare("
            SELECT id, event_data, event_timestamp, created_at 
            FROM {$prefix}events 
            WHERE $whereClause AND id $cursorOp ?
            ORDER BY id $order
            LIMIT ?
        ");
        $params[] = (int)$cursor;
        $params[] = $limit + 1; // Fetch one extra to check if there are more
    } else {
        // Offset-based pagination
        $stmt = $pdo->prepare("
            SELECT id, event_data, event_timestamp, created_at 
            FROM {$prefix}events 
            WHERE $whereClause
            ORDER BY event_timestamp $order, id $order
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit + 1; // Fetch one extra to check if there are more
        $params[] = $offset;
    }

    $stmt->execute($params);
    $events = $stmt->fetchAll();
    
    $hasMore = count($events) > $limit;
    if ($hasMore) {
        array_pop($events); // Remove the extra row
    }

    // Decode JSON data
    foreach ($events as &$event) {
        $event['event_data'] = json_decode($event['event_data'], true);
    }
    
    // Determine next cursor
    $nextCursor = $hasMore && !empty($events) ? $events[count($events) - 1]['id'] : null;

    logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 200);
    
    $response = [
        'status' => 'success',
        'count' => count($events),
        'limit' => $limit,
        'order' => $order,
        'has_more' => $hasMore,
        'events' => $events
    ];
    
    // Include pagination info based on mode
    if ($cursor !== null) {
        $response['cursor'] = $cursor;
        $response['next_cursor'] = $nextCursor;
    } else {
        $response['offset'] = $offset;
        if ($totalCount !== null) {
            $response['total'] = $totalCount;
        }
    }
    
    sendJsonResponse(200, $response);
}

/**
 * Build WHERE clause and parameters for event query.
 * 
 * @return array{0: string, 1: array} Tuple of WHERE clause and parameters
 */
function buildEventQueryConditions(array $input, int $apiKeyId, PDO $pdo, array $config): array
{
    $whereClauses = ['api_key_id = ?'];
    $params = [$apiKeyId];

    if (!empty($input['start_date'])) {
        try {
            $start = new DateTime($input['start_date']);
            $whereClauses[] = 'event_timestamp >= ?';
            $params[] = $start->format('Y-m-d H:i:s.v');
        } catch (Exception $e) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => 'Invalid start_date format. Use ISO 8601 format (e.g., "2024-12-04T10:30:00Z")'
            ]);
        }
    }

    if (!empty($input['end_date'])) {
        try {
            $end = new DateTime($input['end_date']);
            $whereClauses[] = 'event_timestamp <= ?';
            $params[] = $end->format('Y-m-d H:i:s.v');
        } catch (Exception $e) {
            logApiRequest($pdo, $config, $apiKeyId, 'event/query', 'POST', 400);
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => 'Invalid end_date format. Use ISO 8601 format (e.g., "2024-12-04T10:30:00Z")'
            ]);
        }
    }

    return [implode(' AND ', $whereClauses), $params];
}

/**
 * AGGREGATE endpoint - Query pre-aggregated data using schema-based optimizations.
 * 
 * Returns aggregated data (avg, min, max, count) for schema-defined fields,
 * using hourly or daily pre-computed tables for O(hours) or O(days) performance
 * instead of scanning millions of raw events.
 * 
 * Body parameters:
 * - granularity: 'hourly' or 'daily' (default 'daily')
 * - start_date: ISO 8601 timestamp filter (optional)
 * - end_date: ISO 8601 timestamp filter (optional)
 * - fields: array of field names to include (optional, defaults to all)
 */
function handleEventAggregate(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $input = parseJsonBody(API_MAX_PAYLOAD_SIZE);

    if ($input === null) {
        $input = []; // Allow empty body for defaults
    }

    // Parse parameters
    $granularity = $input['granularity'] ?? 'daily';
    if (!in_array($granularity, ['hourly', 'daily'], true)) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => "Invalid granularity '$granularity'. Use 'hourly' or 'daily'"
        ]);
    }

    // Check if schema and aggregation exist
    try {
        $stmt = $pdo->prepare("
            SELECT field_name, field_type
            FROM {$prefix}event_schemas
            WHERE api_key_id = ?
        ");
        $stmt->execute([$apiKeyId]);
        $schemaFields = $stmt->fetchAll();
    } catch (PDOException $e) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 400);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'Schema API not available. Run the migration first.'
        ]);
    }

    if (empty($schemaFields)) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 404);
        sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'No schema defined for this API key. Use POST /api/schema to define one.'
        ]);
    }

    // Check aggregation status
    $stmt = $pdo->prepare("
        SELECT status, row_count, last_updated
        FROM {$prefix}event_aggregations
        WHERE api_key_id = ? AND aggregation_type = ?
    ");
    $stmt->execute([$apiKeyId, $granularity]);
    $aggStatus = $stmt->fetch();

    if (!$aggStatus) {
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 404);
        sendJsonResponse(404, [
            'status' => 'error',
            'message' => "No '$granularity' aggregation configured. Recreate schema with this aggregation type."
        ]);
    }

    if ($aggStatus['status'] !== 'active') {
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 503);
        sendJsonResponse(503, [
            'status' => 'error',
            'message' => "Aggregation is in '{$aggStatus['status']}' state. Try again later or rebuild with POST /api/schema/rebuild"
        ]);
    }

    // Filter fields if specified
    $requestedFields = $input['fields'] ?? null;
    if ($requestedFields !== null && is_array($requestedFields)) {
        $schemaFields = array_filter($schemaFields, fn($f) => in_array($f['field_name'], $requestedFields, true));
    }

    // Build query
    $tableName = "{$prefix}event_stats_{$granularity}_{$apiKeyId}";
    $timeColumn = $granularity === 'hourly' ? 'stat_hour' : 'stat_date';

    // Build SELECT columns
    $selectColumns = ["`$timeColumn` as period", '`event_count`'];
    foreach ($schemaFields as $field) {
        $name = $field['field_name'];
        $type = $field['field_type'];
        
        if (in_array($type, ['integer', 'bigint', 'float', 'double'], true)) {
            $selectColumns[] = "CASE WHEN `count_{$name}` > 0 THEN `sum_{$name}` / `count_{$name}` ELSE NULL END as `avg_{$name}`";
            $selectColumns[] = "`min_{$name}`";
            $selectColumns[] = "`max_{$name}`";
            $selectColumns[] = "`count_{$name}`";
        } else {
            $selectColumns[] = "`count_{$name}`";
        }
    }

    // Build WHERE clause for date filters
    $whereClauses = [];
    $params = [];

    if (!empty($input['start_date'])) {
        try {
            $start = new DateTime($input['start_date']);
            $whereClauses[] = "`$timeColumn` >= ?";
            $params[] = $granularity === 'hourly' 
                ? $start->format('Y-m-d H:i:s')
                : $start->format('Y-m-d');
        } catch (Exception $e) {
            sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid start_date format']);
        }
    }

    if (!empty($input['end_date'])) {
        try {
            $end = new DateTime($input['end_date']);
            $whereClauses[] = "`$timeColumn` <= ?";
            $params[] = $granularity === 'hourly'
                ? $end->format('Y-m-d H:i:s')
                : $end->format('Y-m-d');
        } catch (Exception $e) {
            sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid end_date format']);
        }
    }

    $selectSQL = implode(', ', $selectColumns);
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    $order = ($input['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    try {
        $sql = "SELECT $selectSQL FROM `$tableName` $whereSQL ORDER BY `$timeColumn` $order";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Aggregate query failed: " . $e->getMessage());
        logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 500);
        sendJsonResponse(500, [
            'status' => 'error',
            'message' => 'Failed to query aggregation table. Try rebuilding with POST /api/schema/rebuild'
        ]);
    }

    // Build field metadata for response
    $fieldMeta = [];
    foreach ($schemaFields as $field) {
        $fieldMeta[$field['field_name']] = [
            'type' => $field['field_type'],
            'aggregations' => in_array($field['field_type'], ['integer', 'bigint', 'float', 'double'], true)
                ? ['avg', 'min', 'max', 'count']
                : ['count']
        ];
    }

    logApiRequest($pdo, $config, $apiKeyId, 'event/aggregate', 'POST', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'granularity' => $granularity,
        'count' => count($data),
        'fields' => $fieldMeta,
        'aggregation' => [
            'status' => $aggStatus['status'],
            'row_count' => (int)$aggStatus['row_count'],
            'last_updated' => $aggStatus['last_updated']
        ],
        'data' => $data
    ]);
}

/**
 * STATS endpoint - Get event statistics and daily counts.
 * 
 * Uses pre-computed statistics tables for O(1) lookups on large datasets.
 * Falls back to direct queries if stats tables are not populated.
 */
function handleEventStats(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);

    // Try to get stats from pre-computed table (O(1) instead of O(n))
    $totalEvents = 0;
    $earliestEvent = null;
    $latestEvent = null;
    $usedStatsTable = false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT total_events, earliest_event, latest_event 
            FROM {$prefix}api_key_stats 
            WHERE api_key_id = ?
        ");
        $stmt->execute([$apiKeyId]);
        $stats = $stmt->fetch();
        
        if ($stats) {
            $totalEvents = (int)$stats['total_events'];
            $earliestEvent = $stats['earliest_event'];
            $latestEvent = $stats['latest_event'];
            $usedStatsTable = true;
        }
    } catch (Exception $e) {
        // Stats table might not exist, fall back to direct query
    }
    
    // Fallback to direct query if stats table not populated
    if (!$usedStatsTable) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_events FROM {$prefix}events WHERE api_key_id = ?");
        $stmt->execute([$apiKeyId]);
        $totalEvents = (int)$stmt->fetch()['total_events'];
        
        $stmt = $pdo->prepare("
            SELECT MIN(event_timestamp) as earliest_event, MAX(event_timestamp) as latest_event
            FROM {$prefix}events WHERE api_key_id = ?
        ");
        $stmt->execute([$apiKeyId]);
        $dateRange = $stmt->fetch();
        $earliestEvent = $dateRange['earliest_event'];
        $latestEvent = $dateRange['latest_event'];
    }

    // Daily stats (last 30 days) - use pre-computed stats table
    $dailyStats = [];
    try {
        $stmt = $pdo->prepare("
            SELECT stat_date as date, event_count as count
            FROM {$prefix}event_stats 
            WHERE api_key_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY stat_date DESC
        ");
        $stmt->execute([$apiKeyId]);
        $dailyStats = $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback to direct GROUP BY query (slower but works without stats table)
        $stmt = $pdo->prepare("
            SELECT DATE(event_timestamp) as date, COUNT(*) as count
            FROM {$prefix}events 
            WHERE api_key_id = ? AND event_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(event_timestamp)
            ORDER BY date DESC
        ");
        $stmt->execute([$apiKeyId]);
        $dailyStats = $stmt->fetchAll();
    }

    logApiRequest($pdo, $config, $apiKeyId, 'event/stats', 'GET', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'total_events' => $totalEvents,
        'earliest_event' => $earliestEvent,
        'latest_event' => $latestEvent,
        'daily_stats_last_30_days' => $dailyStats
    ]);
}

/**
 * CLEAR endpoint - Delete all events for the authenticated API key.
 * 
 * Uses streaming output with progress updates for large datasets.
 * Optimized for millions of rows with large batch deletes.
 * 
 * Query parameters:
 * - stream=1: Enable streaming progress output (recommended for large datasets)
 */
function handleEventClear(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $streamOutput = isset($_GET['stream']) && $_GET['stream'] === '1';
    
    // Set unlimited execution time for this operation
    set_time_limit(0);
    ini_set('max_execution_time', '0');
    
    // Set longer MySQL timeout
    $pdo->exec("SET SESSION wait_timeout = 28800"); // 8 hours
    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 600"); // 10 minutes
    
    // Get count using stats table (fast) or estimate
    $estimatedCount = 0;
    try {
        $stmt = $pdo->prepare("SELECT total_events FROM {$prefix}api_key_stats WHERE api_key_id = ?");
        $stmt->execute([$apiKeyId]);
        $result = $stmt->fetch();
        if ($result) {
            $estimatedCount = (int)$result['total_events'];
        }
    } catch (Exception $e) {
        // Stats table might not exist, get actual count (slower)
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM {$prefix}events WHERE api_key_id = ?");
        $stmt->execute([$apiKeyId]);
        $estimatedCount = (int)$stmt->fetch()['cnt'];
    }
    
    if ($streamOutput) {
        // Streaming mode - send progress updates as we delete
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        header('Cache-Control: no-cache');
        
        // Disable output buffering
        if (ob_get_level()) ob_end_flush();
        
        echo "=== Event Clear Started ===\n";
        echo "API Key ID: $apiKeyId\n";
        echo "Estimated events: " . number_format($estimatedCount) . "\n";
        echo "Using batch size: 100,000 rows\n";
        echo "---\n";
        flush();
        
        $totalDeleted = deleteEventsWithProgress($pdo, $prefix, $apiKeyId, $estimatedCount);
        
        // Clear stats tables
        clearEventStats($pdo, $prefix, $apiKeyId);
        
        echo "---\n";
        echo "=== Clear Complete ===\n";
        echo "Total deleted: " . number_format($totalDeleted) . "\n";
        flush();
        
        logApiRequest($pdo, $config, $apiKeyId, 'event/clear', 'DELETE', 200);
        exit;
    }
    
    // Non-streaming mode - delete all then return JSON
    $totalDeleted = deleteEventsBatched($pdo, $prefix, $apiKeyId);
    
    // Clear stats tables
    clearEventStats($pdo, $prefix, $apiKeyId);

    logApiRequest($pdo, $config, $apiKeyId, 'event/clear', 'DELETE', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'All events cleared successfully',
        'deleted_count' => $totalDeleted,
        'api_key_id' => $apiKeyId
    ]);
}

/**
 * Delete events in large batches with streaming progress output.
 */
function deleteEventsWithProgress(PDO $pdo, string $prefix, int $apiKeyId, int $estimatedCount): int
{
    $totalDeleted = 0;
    $batchSize = 100000; // 100k per batch - much faster
    $batchNum = 0;
    $startTime = microtime(true);
    
    do {
        $batchNum++;
        $batchStart = microtime(true);
        
        $stmt = $pdo->prepare("DELETE FROM {$prefix}events WHERE api_key_id = ? LIMIT ?");
        $stmt->execute([$apiKeyId, $batchSize]);
        $deletedThisBatch = $stmt->rowCount();
        $totalDeleted += $deletedThisBatch;
        
        $batchTime = round(microtime(true) - $batchStart, 2);
        $totalTime = round(microtime(true) - $startTime, 1);
        $rate = $batchTime > 0 ? round($deletedThisBatch / $batchTime) : 0;
        $progress = $estimatedCount > 0 ? round(($totalDeleted / $estimatedCount) * 100, 1) : 0;
        
        echo sprintf(
            "Batch %d: deleted %s rows in %.2fs (%s/sec) | Total: %s (%.1f%%) | Elapsed: %.1fs\n",
            $batchNum,
            number_format($deletedThisBatch),
            $batchTime,
            number_format($rate),
            number_format($totalDeleted),
            min($progress, 100),
            $totalTime
        );
        flush();
        
        // Brief pause between batches to allow other queries
        if ($deletedThisBatch === $batchSize) {
            usleep(5000); // 5ms pause
        }
    } while ($deletedThisBatch === $batchSize);
    
    return $totalDeleted;
}

/**
 * Delete events in large batches (no progress output).
 */
function deleteEventsBatched(PDO $pdo, string $prefix, int $apiKeyId): int
{
    $totalDeleted = 0;
    $batchSize = 100000; // 100k per batch
    
    do {
        $stmt = $pdo->prepare("DELETE FROM {$prefix}events WHERE api_key_id = ? LIMIT ?");
        $stmt->execute([$apiKeyId, $batchSize]);
        $deletedThisBatch = $stmt->rowCount();
        $totalDeleted += $deletedThisBatch;
        
        // Brief pause between batches
        if ($deletedThisBatch === $batchSize) {
            usleep(5000); // 5ms
        }
    } while ($deletedThisBatch === $batchSize);
    
    return $totalDeleted;
}

/**
 * Clear event statistics tables for an API key.
 */
function clearEventStats(PDO $pdo, string $prefix, int $apiKeyId): void
{
    try {
        $pdo->prepare("DELETE FROM {$prefix}event_stats WHERE api_key_id = ?")->execute([$apiKeyId]);
        $pdo->prepare("
            UPDATE {$prefix}api_key_stats 
            SET total_events = 0, total_event_bytes = 0, earliest_event = NULL, latest_event = NULL 
            WHERE api_key_id = ?
        ")->execute([$apiKeyId]);
    } catch (Exception $e) {
        // Stats tables might not exist
    }
}

/**
 * Batch insert events using multi-value INSERT for better performance.
 * Processes in chunks of 100 to avoid query size limits.
 * 
 * @return int Number of inserted events
 */
function batchInsertEvents(PDO $pdo, string $prefix, int $apiKeyId, array $insertData): int
{
    if (empty($insertData)) {
        return 0;
    }
    
    $chunkSize = 100; // MySQL handles ~100 rows efficiently per INSERT
    $chunks = array_chunk($insertData, $chunkSize);
    $insertedCount = 0;
    
    foreach ($chunks as $chunk) {
        $placeholders = [];
        $values = [];
        
        foreach ($chunk as $data) {
            $placeholders[] = '(?, ?, ?)';
            $values[] = $apiKeyId;
            $values[] = $data['data'];
            $values[] = $data['timestamp'];
        }
        
        $sql = "INSERT INTO {$prefix}events (api_key_id, event_data, event_timestamp) VALUES " . implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        $insertedCount += count($chunk);
    }
    
    return $insertedCount;
}

/**
 * Update event statistics tables for faster aggregation queries.
 * Uses UPSERT pattern to increment daily counts and aggregate CPM/µSv/h stats.
 * 
 * This enables O(1) timeline queries instead of scanning 9M+ rows.
 */
function updateEventStats(PDO $pdo, string $prefix, int $apiKeyId, array $insertData): void
{
    if (empty($insertData)) {
        return;
    }
    
    // Group events by date for daily stats with CPM/µSv/h aggregation
    $dateStats = [];
    $totalBytes = 0;
    $earliestTimestamp = null;
    $latestTimestamp = null;
    
    foreach ($insertData as $data) {
        $date = substr($data['timestamp'], 0, 10); // Extract YYYY-MM-DD
        $totalBytes += strlen($data['data']);
        
        // Initialize date stats if not exists
        if (!isset($dateStats[$date])) {
            $dateStats[$date] = [
                'count' => 0,
                'sum_cpm' => 0,
                'sum_usvh' => 0,
                'min_cpm' => null,
                'max_cpm' => null,
                'min_usvh' => null,
                'max_usvh' => null,
                'cpm_count' => 0,
                'usvh_count' => 0,
            ];
        }
        
        $dateStats[$date]['count']++;
        
        // Parse event data to extract CPM and µSv/h values
        $eventData = json_decode($data['data'], true);
        if ($eventData) {
            if (isset($eventData['cpm']) && is_numeric($eventData['cpm'])) {
                $cpm = (float)$eventData['cpm'];
                $dateStats[$date]['sum_cpm'] += $cpm;
                $dateStats[$date]['cpm_count']++;
                if ($dateStats[$date]['min_cpm'] === null || $cpm < $dateStats[$date]['min_cpm']) {
                    $dateStats[$date]['min_cpm'] = $cpm;
                }
                if ($dateStats[$date]['max_cpm'] === null || $cpm > $dateStats[$date]['max_cpm']) {
                    $dateStats[$date]['max_cpm'] = $cpm;
                }
            }
            if (isset($eventData['usvh']) && is_numeric($eventData['usvh'])) {
                $usvh = (float)$eventData['usvh'];
                $dateStats[$date]['sum_usvh'] += $usvh;
                $dateStats[$date]['usvh_count']++;
                if ($dateStats[$date]['min_usvh'] === null || $usvh < $dateStats[$date]['min_usvh']) {
                    $dateStats[$date]['min_usvh'] = $usvh;
                }
                if ($dateStats[$date]['max_usvh'] === null || $usvh > $dateStats[$date]['max_usvh']) {
                    $dateStats[$date]['max_usvh'] = $usvh;
                }
            }
        }
        
        // Track min/max timestamps
        if ($earliestTimestamp === null || $data['timestamp'] < $earliestTimestamp) {
            $earliestTimestamp = $data['timestamp'];
        }
        if ($latestTimestamp === null || $data['timestamp'] > $latestTimestamp) {
            $latestTimestamp = $data['timestamp'];
        }
    }
    
    // Update daily stats using UPSERT with CPM/µSv/h aggregates
    $stmt = $pdo->prepare("
        INSERT INTO {$prefix}event_stats (
            api_key_id, stat_date, event_count, 
            sum_cpm, sum_usvh, min_cpm, max_cpm, min_usvh, max_usvh,
            cpm_count, usvh_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            event_count = event_count + VALUES(event_count),
            sum_cpm = COALESCE(sum_cpm, 0) + COALESCE(VALUES(sum_cpm), 0),
            sum_usvh = COALESCE(sum_usvh, 0) + COALESCE(VALUES(sum_usvh), 0),
            min_cpm = LEAST(COALESCE(min_cpm, VALUES(min_cpm)), COALESCE(VALUES(min_cpm), min_cpm)),
            max_cpm = GREATEST(COALESCE(max_cpm, VALUES(max_cpm)), COALESCE(VALUES(max_cpm), max_cpm)),
            min_usvh = LEAST(COALESCE(min_usvh, VALUES(min_usvh)), COALESCE(VALUES(min_usvh), min_usvh)),
            max_usvh = GREATEST(COALESCE(max_usvh, VALUES(max_usvh)), COALESCE(VALUES(max_usvh), max_usvh)),
            cpm_count = cpm_count + VALUES(cpm_count),
            usvh_count = usvh_count + VALUES(usvh_count)
    ");
    
    foreach ($dateStats as $date => $stats) {
        $stmt->execute([
            $apiKeyId, $date, $stats['count'],
            $stats['sum_cpm'], $stats['sum_usvh'],
            $stats['min_cpm'], $stats['max_cpm'],
            $stats['min_usvh'], $stats['max_usvh'],
            $stats['cpm_count'], $stats['usvh_count']
        ]);
    }
    
    // Update API key aggregate stats using UPSERT
    $stmt = $pdo->prepare("
        INSERT INTO {$prefix}api_key_stats (api_key_id, total_events, total_event_bytes, earliest_event, latest_event)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            total_events = total_events + VALUES(total_events),
            total_event_bytes = total_event_bytes + VALUES(total_event_bytes),
            earliest_event = LEAST(COALESCE(earliest_event, VALUES(earliest_event)), VALUES(earliest_event)),
            latest_event = GREATEST(COALESCE(latest_event, VALUES(latest_event)), VALUES(latest_event))
    ");
    $stmt->execute([$apiKeyId, count($insertData), $totalBytes, $earliestTimestamp, $latestTimestamp]);
}

/**
 * Update event key statistics for tracking common event keys.
 * This enables instant loading of common keys in admin dashboards.
 * 
 * Limits to top 100 keys per API key to prevent unbounded growth.
 */
function updateEventKeyStats(PDO $pdo, string $prefix, int $apiKeyId, array $insertData): void
{
    if (empty($insertData)) {
        return;
    }
    
    try {
        // Collect all keys from the batch
        $keyCounts = [];
        foreach ($insertData as $data) {
            $eventData = json_decode($data['data'], true);
            if (is_array($eventData)) {
                foreach (array_keys($eventData) as $key) {
                    // Limit key length to prevent abuse
                    $key = substr($key, 0, 128);
                    $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
                }
            }
        }
        
        if (empty($keyCounts)) {
            return;
        }
        
        // Update key statistics using UPSERT
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}event_key_stats (api_key_id, event_key, occurrence_count)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                occurrence_count = occurrence_count + VALUES(occurrence_count),
                last_seen = CURRENT_TIMESTAMP
        ");
        
        foreach ($keyCounts as $key => $count) {
            $stmt->execute([$apiKeyId, $key, $count]);
        }
        
        // Cleanup: Keep only top 100 keys per API key to prevent unbounded growth
        // This runs occasionally (10% chance) to avoid overhead on every insert
        if (rand(1, 10) === 1) {
            $pdo->prepare("
                DELETE FROM {$prefix}event_key_stats
                WHERE api_key_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id 
                        FROM {$prefix}event_key_stats
                        WHERE api_key_id = ?
                        ORDER BY occurrence_count DESC
                        LIMIT 100
                    ) as top_keys
                )
            ")->execute([$apiKeyId, $apiKeyId]);
        }
    } catch (Exception $e) {
        // Table might not exist yet, silently ignore
        error_log("Event key stats update failed: " . $e->getMessage());
    }
}

/**
 * Get event count using stats table if available (O(1)), fallback to COUNT(*) (O(n)).
 */
function getEventCountFast(PDO $pdo, string $prefix, int $apiKeyId): int
{
    // Try stats table first (fast)
    try {
        $stmt = $pdo->prepare("SELECT total_events FROM {$prefix}api_key_stats WHERE api_key_id = ?");
        $stmt->execute([$apiKeyId]);
        $result = $stmt->fetch();
        if ($result && $result['total_events'] !== null) {
            return (int)$result['total_events'];
        }
    } catch (Exception $e) {
        // Stats table might not exist yet, fall back to COUNT
    }
    
    // Fallback to COUNT(*) - slower for large datasets
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM {$prefix}events WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);
    return (int)$stmt->fetch()['total'];
}

/**
 * Update schema-based aggregation tables if schema is defined for this API key.
 * 
 * This function checks if a schema exists and updates hourly/daily aggregation
 * tables with the incoming event data, enabling O(hours) or O(days) queries
 * instead of scanning millions of raw events.
 */
function updateSchemaAggregations(PDO $pdo, string $prefix, int $apiKeyId, array $insertData): void
{
    if (empty($insertData)) {
        return;
    }

    // Check if schema and aggregations exist for this API key
    try {
        $stmt = $pdo->prepare("
            SELECT s.field_name, s.field_type, a.aggregation_type
            FROM {$prefix}event_schemas s
            JOIN {$prefix}event_aggregations a ON s.api_key_id = a.api_key_id
            WHERE s.api_key_id = ? AND a.status = 'active'
        ");
        $stmt->execute([$apiKeyId]);
        $schemaData = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Schema tables don't exist yet, skip
        return;
    }

    if (empty($schemaData)) {
        return;
    }

    // Build field map and aggregation types
    $fields = [];
    $aggregationTypes = [];
    foreach ($schemaData as $row) {
        if (!isset($fields[$row['field_name']])) {
            $fields[$row['field_name']] = $row['field_type'];
        }
        $aggregationTypes[$row['aggregation_type']] = true;
    }
    $aggregationTypes = array_keys($aggregationTypes);

    // Group events by time period for each aggregation type
    foreach ($aggregationTypes as $aggType) {
        $periodStats = [];

        foreach ($insertData as $data) {
            // Determine period key
            $timestamp = $data['timestamp'];
            $periodKey = match($aggType) {
                'hourly' => substr($timestamp, 0, 13) . ':00:00', // YYYY-MM-DD HH:00:00
                'daily' => substr($timestamp, 0, 10), // YYYY-MM-DD
                default => substr($timestamp, 0, 10)
            };

            if (!isset($periodStats[$periodKey])) {
                $periodStats[$periodKey] = ['event_count' => 0, 'fields' => []];
                foreach ($fields as $fieldName => $fieldType) {
                    $periodStats[$periodKey]['fields'][$fieldName] = [
                        'sum' => 0,
                        'min' => null,
                        'max' => null,
                        'count' => 0,
                        'type' => $fieldType
                    ];
                }
            }

            $periodStats[$periodKey]['event_count']++;

            // Parse event data and aggregate field values
            $eventData = json_decode($data['data'], true);
            if ($eventData) {
                foreach ($fields as $fieldName => $fieldType) {
                    if (isset($eventData[$fieldName])) {
                        $value = $eventData[$fieldName];
                        $fieldStats = &$periodStats[$periodKey]['fields'][$fieldName];
                        $fieldStats['count']++;

                        // For numeric types, track sum/min/max
                        if (in_array($fieldType, ['integer', 'bigint', 'float', 'double'], true) && is_numeric($value)) {
                            $numValue = $fieldType === 'float' || $fieldType === 'double' 
                                ? (float)$value 
                                : (int)$value;
                            $fieldStats['sum'] += $numValue;
                            /** @var int|float|null $currentMin */
                            $currentMin = $fieldStats['min'];
                            /** @var int|float|null $currentMax */
                            $currentMax = $fieldStats['max'];
                            if ($currentMin === null || $numValue < $currentMin) {
                                $fieldStats['min'] = $numValue;
                            }
                            if ($currentMax === null || $numValue > $currentMax) {
                                $fieldStats['max'] = $numValue;
                            }
                        }
                    }
                }
            }
        }

        // Build and execute UPSERT for each period
        $tableName = "{$prefix}event_stats_{$aggType}_{$apiKeyId}";
        $timeColumn = $aggType === 'hourly' ? 'stat_hour' : 'stat_date';

        foreach ($periodStats as $periodKey => $stats) {
            // Build column lists
            $columns = [$timeColumn, 'event_count'];
            $placeholders = ['?', '?'];
            $values = [$periodKey, $stats['event_count']];
            $updateClauses = ['event_count = event_count + VALUES(event_count)'];

            foreach ($stats['fields'] as $fieldName => $fieldStats) {
                $fieldType = $fieldStats['type'];
                
                if (in_array($fieldType, ['integer', 'bigint', 'float', 'double'], true)) {
                    // Numeric field: sum, min, max, count
                    $columns[] = "sum_{$fieldName}";
                    $columns[] = "min_{$fieldName}";
                    $columns[] = "max_{$fieldName}";
                    $columns[] = "count_{$fieldName}";
                    $placeholders = array_merge($placeholders, ['?', '?', '?', '?']);
                    $values[] = $fieldStats['sum'];
                    $values[] = $fieldStats['min'];
                    $values[] = $fieldStats['max'];
                    $values[] = $fieldStats['count'];
                    $updateClauses[] = "sum_{$fieldName} = COALESCE(sum_{$fieldName}, 0) + COALESCE(VALUES(sum_{$fieldName}), 0)";
                    $updateClauses[] = "min_{$fieldName} = LEAST(COALESCE(min_{$fieldName}, VALUES(min_{$fieldName})), COALESCE(VALUES(min_{$fieldName}), min_{$fieldName}))";
                    $updateClauses[] = "max_{$fieldName} = GREATEST(COALESCE(max_{$fieldName}, VALUES(max_{$fieldName})), COALESCE(VALUES(max_{$fieldName}), max_{$fieldName}))";
                    $updateClauses[] = "count_{$fieldName} = count_{$fieldName} + VALUES(count_{$fieldName})";
                } else {
                    // Non-numeric field: just count
                    $columns[] = "count_{$fieldName}";
                    $placeholders[] = '?';
                    $values[] = $fieldStats['count'];
                    $updateClauses[] = "count_{$fieldName} = count_{$fieldName} + VALUES(count_{$fieldName})";
                }
            }

            $columnsSQL = implode(', ', array_map(fn($c) => "`$c`", $columns));
            $placeholdersSQL = implode(', ', $placeholders);
            $updateSQL = implode(', ', $updateClauses);

            try {
                $sql = "
                    INSERT INTO `$tableName` ($columnsSQL)
                    VALUES ($placeholdersSQL)
                    ON DUPLICATE KEY UPDATE $updateSQL
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            } catch (PDOException $e) {
                // Table might not exist or schema mismatch - log and continue
                error_log("Schema aggregation update failed for $tableName: " . $e->getMessage());
            }
        }

        // Update aggregation row count and last_updated
        try {
            $stmt = $pdo->prepare("
                UPDATE {$prefix}event_aggregations
                SET row_count = (SELECT COUNT(*) FROM `$tableName`),
                    last_updated = NOW()
                WHERE api_key_id = ? AND aggregation_type = ?
            ");
            $stmt->execute([$apiKeyId, $aggType]);
        } catch (PDOException $e) {
            // Ignore update errors
        }
    }
}

