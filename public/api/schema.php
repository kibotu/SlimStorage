<?php

declare(strict_types=1);

/**
 * Event Schema API
 * 
 * Define and manage event schemas for optimized aggregation queries.
 * Allows users to define field types and enable hourly/daily pre-aggregation.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';

// =============================================================================
// Schema Field Types (must be defined before use)
// =============================================================================

const SCHEMA_FIELD_TYPES = [
    'integer' => ['mysql_type' => 'SMALLINT UNSIGNED', 'php_cast' => 'intval'],
    'bigint' => ['mysql_type' => 'BIGINT', 'php_cast' => 'intval'],
    'float' => ['mysql_type' => 'FLOAT', 'php_cast' => 'floatval'],
    'double' => ['mysql_type' => 'DOUBLE', 'php_cast' => 'floatval'],
    'string' => ['mysql_type' => 'VARCHAR(255)', 'php_cast' => 'strval'],
    'boolean' => ['mysql_type' => 'TINYINT(1)', 'php_cast' => 'boolval'],
];

const SCHEMA_AGGREGATION_TYPES = ['hourly', 'daily'];
const MAX_SCHEMA_FIELDS = 20;

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

    // Parse endpoint path - extract sub-path after /api/schema
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^/api/schema/?#', '', $requestPath);
    $path = trim($path, '/');
    $method = $_SERVER['REQUEST_METHOD'];

    // Route to handler
    match (true) {
        $method === 'POST' && ($path === '' || $path === 'define') => handleSchemaDefine($pdo, $config, $apiKeyId),
        $method === 'GET' && ($path === '' || $path === 'status') => handleSchemaGet($pdo, $config, $apiKeyId),
        $method === 'DELETE' && ($path === '' || $path === 'remove') => handleSchemaDelete($pdo, $config, $apiKeyId),
        $method === 'POST' && $path === 'rebuild' => handleSchemaRebuild($pdo, $config, $apiKeyId),
        default => sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'Endpoint not found. Available: POST /api/schema, GET /api/schema, DELETE /api/schema, POST /api/schema/rebuild'
        ])
    };
} catch (Exception $e) {
    error_log("Schema API Error: " . $e->getMessage() . " - File: " . $e->getFile() . ":" . $e->getLine());

    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}

// =============================================================================
// POST /api/schema - Define Event Schema
// =============================================================================

/**
 * Define an event schema for optimized aggregations.
 * 
 * Request body:
 * {
 *   "fields": [
 *     {"name": "cpm", "type": "integer"},
 *     {"name": "usvh", "type": "float"}
 *   ],
 *   "aggregations": ["hourly", "daily"]
 * }
 */
function handleSchemaDefine(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);
    $input = parseJsonBody(API_MAX_PAYLOAD_SIZE);

    if ($input === null) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/define', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON']);
    }

    // Validate fields
    if (!isset($input['fields']) || !is_array($input['fields']) || empty($input['fields'])) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/define', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Missing or empty "fields" array']);
    }

    if (count($input['fields']) > MAX_SCHEMA_FIELDS) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/define', 'POST', 400);
        sendJsonResponse(400, ['status' => 'error', 'message' => 'Maximum ' . MAX_SCHEMA_FIELDS . ' fields allowed']);
    }

    // Validate each field
    $fields = [];
    foreach ($input['fields'] as $index => $field) {
        if (!isset($field['name']) || !is_string($field['name'])) {
            sendJsonResponse(400, ['status' => 'error', 'message' => "Field at index $index missing 'name'"]);
        }
        if (!isset($field['type']) || !is_string($field['type'])) {
            sendJsonResponse(400, ['status' => 'error', 'message' => "Field at index $index missing 'type'"]);
        }
        if (!array_key_exists($field['type'], SCHEMA_FIELD_TYPES)) {
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Invalid type '{$field['type']}' at index $index. Valid types: " . implode(', ', array_keys(SCHEMA_FIELD_TYPES))
            ]);
        }
        // Validate field name (alphanumeric + underscore, max 64 chars)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $field['name'])) {
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Invalid field name '{$field['name']}' at index $index. Must be alphanumeric with underscores, start with letter/underscore, max 64 chars"
            ]);
        }
        $fields[] = [
            'name' => $field['name'],
            'type' => $field['type']
        ];
    }

    // Validate aggregations
    $aggregations = $input['aggregations'] ?? ['daily'];
    if (!is_array($aggregations)) {
        $aggregations = [$aggregations];
    }
    foreach ($aggregations as $agg) {
        if (!in_array($agg, SCHEMA_AGGREGATION_TYPES, true)) {
            sendJsonResponse(400, [
                'status' => 'error',
                'message' => "Invalid aggregation type '$agg'. Valid types: " . implode(', ', SCHEMA_AGGREGATION_TYPES)
            ]);
        }
    }

    // Check if schema already exists
    $existingSchema = getSchemaForApiKey($pdo, $prefix, $apiKeyId);
    if (!empty($existingSchema)) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/define', 'POST', 409);
        sendJsonResponse(409, [
            'status' => 'error',
            'message' => 'Schema already exists for this API key. Delete it first or create a new API key.',
            'existing_fields' => count($existingSchema)
        ]);
    }

    // Step 1: Create aggregation tables first (DDL causes implicit commit)
    // These are created before inserting records so we can clean up on failure
    foreach ($aggregations as $aggType) {
        createAggregationTable($pdo, $prefix, $apiKeyId, $aggType, $fields);
    }

    // Step 2: Insert schema and aggregation records (DML)
    // Insert field definitions
    $stmt = $pdo->prepare("
        INSERT INTO {$prefix}event_schemas (api_key_id, field_name, field_type)
        VALUES (?, ?, ?)
    ");
    foreach ($fields as $field) {
        $stmt->execute([$apiKeyId, $field['name'], $field['type']]);
    }

    // Insert aggregation records
    foreach ($aggregations as $aggType) {
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}event_aggregations (api_key_id, aggregation_type, status)
            VALUES (?, ?, 'active')
        ");
        $stmt->execute([$apiKeyId, $aggType]);
    }

    logApiRequest($pdo, $config, $apiKeyId, 'schema/define', 'POST', 201);
    sendJsonResponse(201, [
        'status' => 'success',
        'message' => 'Schema created successfully',
        'schema' => [
            'fields' => $fields,
            'aggregations' => $aggregations
        ]
    ]);
}

// =============================================================================
// GET /api/schema - Get Schema Status
// =============================================================================

/**
 * Get the current schema and aggregation status for an API key.
 */
function handleSchemaGet(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);

    // Get schema fields
    $fields = getSchemaForApiKey($pdo, $prefix, $apiKeyId);

    if (empty($fields)) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/get', 'GET', 200);
        sendJsonResponse(200, [
            'status' => 'success',
            'schema' => null,
            'message' => 'No schema defined for this API key'
        ]);
    }

    // Get aggregation status
    $stmt = $pdo->prepare("
        SELECT aggregation_type, status, row_count, last_updated, created_at
        FROM {$prefix}event_aggregations
        WHERE api_key_id = ?
    ");
    $stmt->execute([$apiKeyId]);
    $aggregations = [];
    while ($row = $stmt->fetch()) {
        $aggregations[$row['aggregation_type']] = [
            'status' => $row['status'],
            'row_count' => (int)$row['row_count'],
            'last_updated' => $row['last_updated'],
            'created_at' => $row['created_at']
        ];
    }

    // Get event stats
    $stmt = $pdo->prepare("
        SELECT total_events, earliest_event, latest_event
        FROM {$prefix}api_key_stats
        WHERE api_key_id = ?
    ");
    $stmt->execute([$apiKeyId]);
    $stats = $stmt->fetch() ?: ['total_events' => 0, 'earliest_event' => null, 'latest_event' => null];

    // Get field statistics from aggregation tables
    $fieldStats = [];
    foreach ($fields as $field) {
        $fieldStats[$field['field_name']] = getFieldStats($pdo, $prefix, $apiKeyId, $field['field_name']);
    }

    logApiRequest($pdo, $config, $apiKeyId, 'schema/get', 'GET', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'schema' => [
            'fields' => array_map(fn($f) => [
                'name' => $f['field_name'],
                'type' => $f['field_type'],
                'stats' => $fieldStats[$f['field_name']] ?? null
            ], $fields),
            'aggregations' => $aggregations
        ],
        'events' => [
            'total' => (int)$stats['total_events'],
            'earliest' => $stats['earliest_event'],
            'latest' => $stats['latest_event']
        ]
    ]);
}

// =============================================================================
// DELETE /api/schema - Remove Schema
// =============================================================================

/**
 * Remove schema and aggregation tables (keeps raw events).
 */
function handleSchemaDelete(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);

    // Check if schema exists
    $fields = getSchemaForApiKey($pdo, $prefix, $apiKeyId);
    if (empty($fields)) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/delete', 'DELETE', 404);
        sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'No schema defined for this API key'
        ]);
    }

    // Get aggregation types to drop tables
    $stmt = $pdo->prepare("SELECT aggregation_type FROM {$prefix}event_aggregations WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);
    $aggregations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Drop aggregation tables first (DDL causes implicit commit, no transaction)
    foreach ($aggregations as $aggType) {
        $tableName = getAggregationTableName($prefix, $apiKeyId, $aggType);
        $pdo->exec("DROP TABLE IF EXISTS `$tableName`");
    }

    // Delete records (simple DML, no transaction needed for atomic delete)
    $stmt = $pdo->prepare("DELETE FROM {$prefix}event_aggregations WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);

    $stmt = $pdo->prepare("DELETE FROM {$prefix}event_schemas WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);

    logApiRequest($pdo, $config, $apiKeyId, 'schema/delete', 'DELETE', 200);
    sendJsonResponse(200, [
        'status' => 'success',
        'message' => 'Schema and aggregation tables removed. Raw events preserved.',
        'removed' => [
            'fields' => count($fields),
            'aggregations' => $aggregations
        ]
    ]);
}

// =============================================================================
// POST /api/schema/rebuild - Rebuild Aggregations from Raw Events
// =============================================================================

/**
 * Rebuild aggregation tables from raw events.
 * Useful after importing events or if aggregations get out of sync.
 */
function handleSchemaRebuild(PDO $pdo, array $config, int $apiKeyId): never
{
    $prefix = getDbPrefix($config);

    // Check if schema exists
    $fields = getSchemaForApiKey($pdo, $prefix, $apiKeyId);
    if (empty($fields)) {
        logApiRequest($pdo, $config, $apiKeyId, 'schema/rebuild', 'POST', 404);
        sendJsonResponse(404, [
            'status' => 'error',
            'message' => 'No schema defined for this API key'
        ]);
    }

    // Get aggregation types
    $stmt = $pdo->prepare("SELECT aggregation_type FROM {$prefix}event_aggregations WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);
    $aggregations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($aggregations)) {
        sendJsonResponse(400, ['status' => 'error', 'message' => 'No aggregations configured']);
    }

    // Set status to building
    $stmt = $pdo->prepare("UPDATE {$prefix}event_aggregations SET status = 'building' WHERE api_key_id = ?");
    $stmt->execute([$apiKeyId]);

    $rebuilt = [];
    try {
        foreach ($aggregations as $aggType) {
            $rowCount = rebuildAggregationTable($pdo, $prefix, $apiKeyId, $aggType, $fields);
            $rebuilt[$aggType] = $rowCount;

            // Update status
            $stmt = $pdo->prepare("
                UPDATE {$prefix}event_aggregations 
                SET status = 'active', row_count = ?, last_updated = NOW()
                WHERE api_key_id = ? AND aggregation_type = ?
            ");
            $stmt->execute([$rowCount, $apiKeyId, $aggType]);
        }

        logApiRequest($pdo, $config, $apiKeyId, 'schema/rebuild', 'POST', 200);
        sendJsonResponse(200, [
            'status' => 'success',
            'message' => 'Aggregations rebuilt successfully',
            'rebuilt' => $rebuilt
        ]);
    } catch (Exception $e) {
        // Set status to error
        $stmt = $pdo->prepare("UPDATE {$prefix}event_aggregations SET status = 'error' WHERE api_key_id = ?");
        $stmt->execute([$apiKeyId]);
        throw $e;
    }
}

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Get schema fields for an API key.
 */
function getSchemaForApiKey(PDO $pdo, string $prefix, int $apiKeyId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT field_name, field_type, created_at
            FROM {$prefix}event_schemas
            WHERE api_key_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$apiKeyId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table might not exist
        return [];
    }
}

/**
 * Get aggregation table name for an API key and type.
 */
function getAggregationTableName(string $prefix, int $apiKeyId, string $aggType): string
{
    return "{$prefix}event_stats_{$aggType}_{$apiKeyId}";
}

/**
 * Create aggregation table with dynamic columns based on schema.
 */
function createAggregationTable(PDO $pdo, string $prefix, int $apiKeyId, string $aggType, array $fields): void
{
    $tableName = getAggregationTableName($prefix, $apiKeyId, $aggType);
    
    // Determine time column based on aggregation type
    $timeColumn = match($aggType) {
        'hourly' => '`stat_hour` DATETIME NOT NULL',
        'daily' => '`stat_date` DATE NOT NULL',
        default => throw new Exception("Unknown aggregation type: $aggType")
    };
    $timeKey = $aggType === 'hourly' ? 'stat_hour' : 'stat_date';

    // Build dynamic columns for each field
    $fieldColumns = [];
    foreach ($fields as $field) {
        // Handle both array formats: from API input (name/type) and from DB (field_name/field_type)
        $name = $field['name'] ?? $field['field_name'];
        $type = $field['type'] ?? $field['field_type'];
        
        // For numeric types, add sum/min/max/count columns
        if (in_array($type, ['integer', 'bigint', 'float', 'double'], true)) {
            $sumType = in_array($type, ['float', 'double']) ? 'DOUBLE' : 'BIGINT';
            $valType = SCHEMA_FIELD_TYPES[$type]['mysql_type'];
            
            $fieldColumns[] = "`sum_{$name}` $sumType NULL";
            $fieldColumns[] = "`min_{$name}` $valType NULL";
            $fieldColumns[] = "`max_{$name}` $valType NULL";
            $fieldColumns[] = "`count_{$name}` INT UNSIGNED NOT NULL DEFAULT 0";
        }
        // For string/boolean, just count occurrences
        else {
            $fieldColumns[] = "`count_{$name}` INT UNSIGNED NOT NULL DEFAULT 0";
        }
    }

    $columnsSQL = implode(",\n            ", $fieldColumns);

    // Drop existing table first to ensure clean state
    $pdo->exec("DROP TABLE IF EXISTS `$tableName`");
    
    $sql = "
        CREATE TABLE `$tableName` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            $timeColumn,
            `event_count` INT UNSIGNED NOT NULL DEFAULT 0,
            $columnsSQL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_time` (`$timeKey`),
            INDEX `idx_time` (`$timeKey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($sql);
}

/**
 * Rebuild aggregation table from raw events.
 * 
 * @return int Number of rows in rebuilt table
 */
function rebuildAggregationTable(PDO $pdo, string $prefix, int $apiKeyId, string $aggType, array $fields): int
{
    $tableName = getAggregationTableName($prefix, $apiKeyId, $aggType);
    
    // Truncate existing data
    $pdo->exec("TRUNCATE TABLE `$tableName`");

    // Determine grouping expression
    $timeExpr = match($aggType) {
        'hourly' => "DATE_FORMAT(event_timestamp, '%Y-%m-%d %H:00:00')",
        'daily' => 'DATE(event_timestamp)',
        default => throw new Exception("Unknown aggregation type: $aggType")
    };
    $timeColumn = $aggType === 'hourly' ? 'stat_hour' : 'stat_date';

    // Build SELECT columns for each field
    $selectColumns = [];
    $insertColumns = [$timeColumn, 'event_count'];
    
    foreach ($fields as $field) {
        // Handle both array formats: from API input (name/type) and from DB (field_name/field_type)
        $name = $field['name'] ?? $field['field_name'];
        $type = $field['type'] ?? $field['field_type'];
        $jsonPath = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.$name'))";
        
        if (in_array($type, ['integer', 'bigint', 'float', 'double'], true)) {
            $castType = in_array($type, ['float', 'double']) ? 'DECIMAL(20,6)' : 'SIGNED';
            
            $selectColumns[] = "SUM(CAST($jsonPath AS $castType)) as `sum_{$name}`";
            $selectColumns[] = "MIN(CAST($jsonPath AS $castType)) as `min_{$name}`";
            $selectColumns[] = "MAX(CAST($jsonPath AS $castType)) as `max_{$name}`";
            $selectColumns[] = "SUM(CASE WHEN JSON_EXTRACT(event_data, '\$.$name') IS NOT NULL THEN 1 ELSE 0 END) as `count_{$name}`";
            
            $insertColumns = array_merge($insertColumns, ["sum_{$name}", "min_{$name}", "max_{$name}", "count_{$name}"]);
        } else {
            $selectColumns[] = "SUM(CASE WHEN JSON_EXTRACT(event_data, '\$.$name') IS NOT NULL THEN 1 ELSE 0 END) as `count_{$name}`";
            $insertColumns[] = "count_{$name}";
        }
    }

    $selectSQL = implode(",\n                ", $selectColumns);
    $insertColumnsSQL = implode(', ', array_map(fn($c) => "`$c`", $insertColumns));

    $sql = "
        INSERT INTO `$tableName` ($insertColumnsSQL)
        SELECT 
            $timeExpr as `$timeColumn`,
            COUNT(*) as `event_count`,
            $selectSQL
        FROM {$prefix}events
        WHERE api_key_id = ?
        GROUP BY $timeExpr
        ORDER BY $timeExpr ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$apiKeyId]);

    // Get row count
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$tableName`");
    return (int)$stmt->fetchColumn();
}

/**
 * Get field statistics from daily aggregation table.
 */
function getFieldStats(PDO $pdo, string $prefix, int $apiKeyId, string $fieldName): ?array
{
    // Try daily table first
    $tableName = getAggregationTableName($prefix, $apiKeyId, 'daily');
    
    try {
        // Check if sum column exists (numeric field)
        $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE 'sum_{$fieldName}'");
        if ($stmt->fetch()) {
            $stmt = $pdo->query("
                SELECT 
                    SUM(`sum_{$fieldName}`) / NULLIF(SUM(`count_{$fieldName}`), 0) as avg_value,
                    MIN(`min_{$fieldName}`) as min_value,
                    MAX(`max_{$fieldName}`) as max_value,
                    SUM(`count_{$fieldName}`) as total_count
                FROM `$tableName`
            ");
            $stats = $stmt->fetch();
            if ($stats && $stats['total_count'] > 0) {
                return [
                    'avg' => $stats['avg_value'] !== null ? round((float)$stats['avg_value'], 4) : null,
                    'min' => $stats['min_value'] !== null ? (float)$stats['min_value'] : null,
                    'max' => $stats['max_value'] !== null ? (float)$stats['max_value'] : null,
                    'count' => (int)$stats['total_count']
                ];
            }
        } else {
            // Non-numeric field, just get count
            $stmt = $pdo->query("SELECT SUM(`count_{$fieldName}`) as total_count FROM `$tableName`");
            $stats = $stmt->fetch();
            if ($stats) {
                return ['count' => (int)$stats['total_count']];
            }
        }
    } catch (PDOException $e) {
        // Table might not exist
    }

    return null;
}

