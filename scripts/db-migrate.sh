#!/bin/bash
#
# Database Migration Script
# Comprehensive migration tool for schema updates, indexes, stats, and optimization
# Safe to run multiple times
# Usage: ./scripts/db-migrate.sh [action]
#
# Actions:
#   indexes  - Add performance indexes and cursor pagination support
#   schema   - Add event_schemas and event_aggregations tables
#   stats    - Add statistics tables with CPM/µSv/h aggregation
#   optimize - Analyze and optimize database tables
#   all      - Run all migrations (default)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SECRETS_FILE="$PROJECT_ROOT/.secrets.yml"

ACTION="${1:-all}"

echo "🗄️  Database Migration"
echo ""
echo "Action: $ACTION"
echo ""

case "$ACTION" in
    indexes)
        echo "This will apply:"
        echo "  • Add status_code column to api_logs (if missing)"
        echo "  • Add cursor pagination indexes"
        echo "  • Add covering indexes for log filtering"
        ;;
    schema)
        echo "This will apply:"
        echo "  • Add event_schemas table (for field definitions)"
        echo "  • Add event_aggregations table (for aggregation status)"
        ;;
    stats)
        echo "This will apply:"
        echo "  • Add statistics tables (event_stats, api_key_stats)"
        echo "  • Add CPM/µSv/h aggregation columns"
        echo "  • Add generated columns and performance indexes"
        echo "  • Populate statistics from existing data"
        echo ""
        echo "⚠️  WARNING: For large tables (9M+ rows), this may take 10-30 minutes!"
        ;;
    optimize)
        echo "This will:"
        echo "  • Analyze table sizes and fragmentation"
        echo "  • Provide optimization recommendations"
        echo ""
        echo "Note: Add 'compress', 'full', or 'update_stats' as second argument for actions"
        ;;
    all)
        echo "This will apply ALL migrations:"
        echo "  • Schema updates (event_schemas, event_aggregations)"
        echo "  • Statistics tables with CPM/µSv/h aggregation"
        echo "  • Performance indexes and cursor pagination"
        echo "  • Populate stats from existing data"
        echo ""
        echo "⚠️  WARNING: For large tables, this may take 10-30 minutes!"
        ;;
    *)
        echo "❌ Unknown action: $ACTION"
        echo ""
        echo "Available actions:"
        echo "  indexes  - Add performance indexes"
        echo "  schema   - Add schema API tables"
        echo "  stats    - Add statistics tables"
        echo "  optimize [compress|full|update_stats] - Analyze/optimize database"
        echo "  all      - Run all migrations (default)"
        exit 1
        ;;
esac

echo ""
read -p "Continue with migration? (y/N): " CONFIRM

if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
    echo "❌ Aborted."
    exit 1
fi

echo ""

# Check if secrets file exists
if [ ! -f "$SECRETS_FILE" ]; then
    echo "❌ Error: .secrets.yml not found at $SECRETS_FILE"
    exit 1
fi

# Parse YAML file
parse_yaml() {
    local prefix=$1
    local s='[[:space:]]*'
    local w='[a-zA-Z0-9_]*'
    local fs=$(echo @|tr @ '\034')
    sed -ne "s|^\($s\):|\1|" \
         -e "s|^\($s\)\($w\)$s:$s[\"']\(.*\)[\"']$s\$|\1$fs\2$fs\3|p" \
         -e "s|^\($s\)\($w\)$s:$s\(.*\)$s\$|\1$fs\2$fs\3|p" "$SECRETS_FILE" |
    awk -F$fs '{
        indent = length($1)/2;
        vname[indent] = $2;
        for (i in vname) {if (i > indent) {delete vname[i]}}
        if (length($3) > 0) {
            vn=""; for (i=0; i<indent; i++) {vn=(vn)(vname[i])("_")}
            printf("%s%s=\"%s\"\n", "'$prefix'",vn $2, $3);
        }
    }'
}

# Load configuration
eval $(parse_yaml)

FTP_USER="${ftp_user}"
FTP_PASSWORD="${ftp_password}"
FTP_HOST=$(echo "${ftp_protocol}" | sed 's|ftp://||')
REMOTE_PATH="${domain_public_folder}"
DOMAIN="${domain_name}"

if [ -z "$FTP_USER" ] || [ -z "$FTP_PASSWORD" ] || [ -z "$FTP_HOST" ] || [ -z "$REMOTE_PATH" ] || [ -z "$DOMAIN" ]; then
    echo "❌ Error: Missing configuration in .secrets.yml"
    exit 1
fi

# Generate a random token for security
MIGRATE_TOKEN=$(openssl rand -hex 32)

echo "📋 Configuration:"
echo "   Domain: $DOMAIN"
echo "   FTP Host: $FTP_HOST"
echo ""

# Check if lftp is installed
if ! command -v lftp &> /dev/null; then
    echo "❌ Error: lftp is not installed"
    echo "   Install with: brew install lftp (macOS) or apt-get install lftp (Linux)"
    exit 1
fi

# Create temporary migration PHP file
MIGRATE_FILE=$(mktemp)
MIGRATE_FILENAME="_migrate_$(date +%s).php"

# Generate PHP migration script based on action
cat > "$MIGRATE_FILE" <<'MIGRATE_PHP_HEADER'
<?php
/**
 * Database Migration Script
 * Comprehensive migration tool for schema, indexes, stats, and optimization
 * This file self-destructs after execution
 */

// Security token check
$expectedToken = 'TOKEN_PLACEHOLDER';
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');
set_time_limit(3600); // Allow up to 1 hour for large migrations

require_once __DIR__ . '/config.php';

// Utility functions
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetch()['cnt'] > 0;
}

function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $indexName]);
    return (int)$stmt->fetch()['cnt'] > 0;
}

function addColumnIfNotExists(PDO $pdo, string $table, string $columnName, string $columnDef): bool {
    if (columnExists($pdo, $table, $columnName)) {
        echo "  ⏭ Column {$columnName} already exists\n";
        return false;
    }
    
    try {
        echo "  ⏳ Adding {$columnName}...\n";
        $pdo->exec("ALTER TABLE `{$table}` ADD {$columnDef}");
        echo "  ✓ Added column {$columnName}\n";
        return true;
    } catch (PDOException $e) {
        echo "  ⚠ Failed: {$e->getMessage()}\n";
        return false;
    }
}

function addIndexIfNotExists(PDO $pdo, string $table, string $indexName, string $indexDef): bool {
    if (indexExists($pdo, $table, $indexName)) {
        echo "  ⏭ Index {$indexName} already exists\n";
        return false;
    }
    
    try {
        echo "  ⏳ Adding {$indexName} (may take a while on large tables)...\n";
        $pdo->exec("ALTER TABLE `{$table}` ADD {$indexDef}");
        echo "  ✓ Added index {$indexName}\n";
        return true;
    } catch (PDOException $e) {
        echo "  ⚠ Failed: {$e->getMessage()}\n";
        return false;
    }
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) {
        return sprintf("%.2f GiB", $bytes / 1073741824);
    } elseif ($bytes >= 1048576) {
        return sprintf("%.2f MiB", $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf("%.2f KiB", $bytes / 1024);
    }
    return $bytes . " B";
}

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);
    $prefix = getDbPrefix($config);
    
    $action = $_GET['action'] ?? 'all';
    $subAction = $_GET['sub'] ?? 'analyze';
    
    echo "🗄️  Database Migration\n";
    echo "================================\n";
    echo "Action: {$action}\n\n";
    
    $addedColumns = 0;
    $addedIndexes = 0;
    $migrations = [];
    
MIGRATE_PHP_HEADER

# Add action-specific PHP code
case "$ACTION" in
    indexes|all)
        cat >> "$MIGRATE_FILE" <<'MIGRATE_PHP_INDEXES'
    // =====================================================
    // INDEXES: Performance indexes and cursor pagination
    // =====================================================
    if ($action === 'indexes' || $action === 'all') {
        echo "📋 Adding Performance Indexes\n";
        echo "================================\n\n";
        
        // Add status_code column to api_logs if missing
        echo "📋 {$prefix}api_logs - Schema Updates:\n";
        if (addColumnIfNotExists($pdo, "{$prefix}api_logs", "status_code", 
            "COLUMN `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200 AFTER `method`")) {
            $addedColumns++;
        }
        echo "\n";
        
        // Events table - cursor pagination
        echo "📋 {$prefix}events - Indexes:\n";
        if (addIndexIfNotExists($pdo, "{$prefix}events", "idx_api_key_id_desc", 
            "INDEX `idx_api_key_id_desc` (`api_key_id`, `id` DESC)")) {
            $addedIndexes++;
        }
        echo "\n";
        
        // API Logs table - cursor pagination
        echo "📋 {$prefix}api_logs - Indexes:\n";
        if (addIndexIfNotExists($pdo, "{$prefix}api_logs", "idx_api_key_id_desc", 
            "INDEX `idx_api_key_id_desc` (`api_key_id`, `id` DESC)")) {
            $addedIndexes++;
        }
        
        // API Logs - covering index for filtered queries
        if (addIndexIfNotExists($pdo, "{$prefix}api_logs", "idx_logs_covering", 
            "INDEX `idx_logs_covering` (`api_key_id`, `created_at`, `id`, `status_code`)")) {
            $addedIndexes++;
        }
        echo "\n";
        
        $migrations[] = "Performance indexes added";
    }
    
MIGRATE_PHP_INDEXES
        ;;
esac

case "$ACTION" in
    schema|all)
        cat >> "$MIGRATE_FILE" <<'MIGRATE_PHP_SCHEMA'
    // =====================================================
    // SCHEMA: Event schemas and aggregations tables
    // =====================================================
    if ($action === 'schema' || $action === 'all') {
        echo "📋 Adding Schema API Tables\n";
        echo "================================\n\n";
        
        // Create event_schemas table
        echo "1. Creating event_schemas table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}event_schemas` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `field_name` VARCHAR(64) NOT NULL,
                    `field_type` ENUM('integer', 'bigint', 'float', 'double', 'string', 'boolean') NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_field` (`api_key_id`, `field_name`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}event_schemas\n";
            $migrations[] = 'event_schemas table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}event_schemas\n";
            }
        }
        
        // Create event_aggregations table
        echo "\n2. Creating event_aggregations table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}event_aggregations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `aggregation_type` ENUM('hourly', 'daily') NOT NULL,
                    `status` ENUM('pending', 'building', 'active', 'error') NOT NULL DEFAULT 'pending',
                    `row_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `last_updated` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_agg` (`api_key_id`, `aggregation_type`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}event_aggregations\n";
            $migrations[] = 'event_aggregations table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}event_aggregations\n";
            }
        }
        
        echo "\n";
        $migrations[] = "Schema API tables added";
    }
    
MIGRATE_PHP_SCHEMA
        ;;
esac

case "$ACTION" in
    stats|all)
        cat >> "$MIGRATE_FILE" <<'MIGRATE_PHP_STATS'
    // =====================================================
    // STATS: Statistics tables with CPM/µSv/h aggregation
    // =====================================================
    if ($action === 'stats' || $action === 'all') {
        echo "📋 Adding Statistics Tables\n";
        echo "================================\n\n";
        
        // Get event count for progress reporting
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}events`");
        $eventCount = (int)$stmt->fetch()['cnt'];
        echo "📊 Found {$eventCount} existing events\n\n";
        
        // Create event_stats table
        echo "1. Creating/updating event_stats table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}event_stats` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `stat_date` DATE NOT NULL,
                    `event_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `sum_cpm` DECIMAL(20,4) NULL DEFAULT 0,
                    `sum_usvh` DECIMAL(20,6) NULL DEFAULT 0,
                    `min_cpm` DECIMAL(10,4) NULL,
                    `max_cpm` DECIMAL(10,4) NULL,
                    `min_usvh` DECIMAL(10,6) NULL,
                    `max_usvh` DECIMAL(10,6) NULL,
                    `cpm_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `usvh_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_date` (`api_key_id`, `stat_date`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    INDEX `idx_stat_date` (`stat_date`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}event_stats\n";
            $migrations[] = 'event_stats table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}event_stats\n";
            }
        }
        
        // Create api_key_stats table
        echo "\n2. Creating api_key_stats table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}api_key_stats` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL UNIQUE,
                    `total_events` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `total_kv_pairs` INT UNSIGNED NOT NULL DEFAULT 0,
                    `total_kv_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `total_event_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `earliest_event` DATETIME(3) NULL,
                    `latest_event` DATETIME(3) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_api_key_id` (`api_key_id`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}api_key_stats\n";
            $migrations[] = 'api_key_stats table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}api_key_stats\n";
            }
        }
        
        // Add event_date generated column
        echo "\n3. Adding event_date generated column...\n";
        if (!columnExists($pdo, "{$prefix}events", 'event_date')) {
            try {
                echo "   ⏳ Adding event_date column (processing {$eventCount} rows)...\n";
                $startTime = microtime(true);
                $pdo->exec("ALTER TABLE `{$prefix}events` ADD COLUMN `event_date` DATE GENERATED ALWAYS AS (DATE(event_timestamp)) STORED AFTER `event_timestamp`");
                $elapsed = round(microtime(true) - $startTime, 1);
                echo "   ✓ Added: event_date column ({$elapsed}s)\n";
                $migrations[] = "events.event_date column";
            } catch (PDOException $e) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            }
        } else {
            echo "   ⏭ Column exists: event_date\n";
        }
        
        // Add performance indexes
        echo "\n4. Adding performance indexes...\n";
        $indexes = [
            'idx_api_key_date' => ['table' => 'events', 'def' => '(`api_key_id`, `event_date`)'],
            'idx_api_key_timestamp' => ['table' => 'events', 'def' => '(`api_key_id`, `event_timestamp`)'],
            'idx_api_logs_key_date' => ['table' => 'api_logs', 'def' => '(`api_key_id`, `created_at`)'],
            'idx_api_logs_endpoint' => ['table' => 'api_logs', 'def' => '(`endpoint`)'],
            'idx_api_logs_status' => ['table' => 'api_logs', 'def' => '(`status_code`)'],
        ];
        
        foreach ($indexes as $name => $info) {
            $table = "{$prefix}{$info['table']}";
            if (addIndexIfNotExists($pdo, $table, $name, "INDEX `{$name}` {$info['def']}")) {
                $addedIndexes++;
            }
        }
        
        // Populate event_stats
        echo "\n5. Populating event_stats with CPM/µSv/h aggregates...\n";
        try {
            if ($eventCount > 0) {
                echo "   ⏳ Aggregating daily statistics from {$eventCount} events...\n";
                $startTime = microtime(true);
                
                $pdo->exec("
                    INSERT INTO `{$prefix}event_stats` (
                        `api_key_id`, `stat_date`, `event_count`,
                        `sum_cpm`, `sum_usvh`, `min_cpm`, `max_cpm`, `min_usvh`, `max_usvh`,
                        `cpm_count`, `usvh_count`
                    )
                    SELECT 
                        `api_key_id`, 
                        DATE(`event_timestamp`) as `stat_date`, 
                        COUNT(*) as `event_count`,
                        SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.cpm')) AS DECIMAL(10,4))) as `sum_cpm`,
                        SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.usvh')) AS DECIMAL(10,6))) as `sum_usvh`,
                        MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.cpm')) AS DECIMAL(10,4))) as `min_cpm`,
                        MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.cpm')) AS DECIMAL(10,4))) as `max_cpm`,
                        MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.usvh')) AS DECIMAL(10,6))) as `min_usvh`,
                        MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.usvh')) AS DECIMAL(10,6))) as `max_usvh`,
                        SUM(CASE WHEN JSON_EXTRACT(event_data, '\$.cpm') IS NOT NULL THEN 1 ELSE 0 END) as `cpm_count`,
                        SUM(CASE WHEN JSON_EXTRACT(event_data, '\$.usvh') IS NOT NULL THEN 1 ELSE 0 END) as `usvh_count`
                    FROM `{$prefix}events`
                    GROUP BY `api_key_id`, DATE(`event_timestamp`)
                    ON DUPLICATE KEY UPDATE 
                        `event_count` = VALUES(`event_count`),
                        `sum_cpm` = VALUES(`sum_cpm`),
                        `sum_usvh` = VALUES(`sum_usvh`),
                        `min_cpm` = VALUES(`min_cpm`),
                        `max_cpm` = VALUES(`max_cpm`),
                        `min_usvh` = VALUES(`min_usvh`),
                        `max_usvh` = VALUES(`max_usvh`),
                        `cpm_count` = VALUES(`cpm_count`),
                        `usvh_count` = VALUES(`usvh_count`)
                ");
                
                $elapsed = round(microtime(true) - $startTime, 1);
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}event_stats`");
                $statsCount = (int)$stmt->fetch()['cnt'];
                echo "   ✓ Populated: {$statsCount} daily stat records ({$elapsed}s)\n";
                $migrations[] = "event_stats populated ({$statsCount} records)";
            } else {
                echo "   ⏭ No existing events to process\n";
            }
        } catch (PDOException $e) {
            echo "   ⚠ Warning: {$e->getMessage()}\n";
        }
        
        // Populate api_key_stats
        echo "\n6. Populating api_key_stats...\n";
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}api_keys`");
            $keyCount = (int)$stmt->fetch()['cnt'];
            
            if ($keyCount > 0) {
                echo "   ⏳ Calculating statistics for {$keyCount} API keys...\n";
                $startTime = microtime(true);
                
                $pdo->exec("
                    INSERT INTO `{$prefix}api_key_stats` 
                        (`api_key_id`, `total_events`, `total_kv_pairs`, `total_kv_bytes`, `total_event_bytes`, `earliest_event`, `latest_event`)
                    SELECT 
                        ak.id,
                        COALESCE(e.event_count, 0),
                        COALESCE(kv.kv_count, 0),
                        COALESCE(kv.kv_bytes, 0),
                        COALESCE(e.event_bytes, 0),
                        e.earliest_event,
                        e.latest_event
                    FROM `{$prefix}api_keys` ak
                    LEFT JOIN (
                        SELECT 
                            api_key_id, 
                            COUNT(*) as event_count, 
                            SUM(LENGTH(event_data)) as event_bytes,
                            MIN(event_timestamp) as earliest_event, 
                            MAX(event_timestamp) as latest_event
                        FROM `{$prefix}events` 
                        GROUP BY api_key_id
                    ) e ON e.api_key_id = ak.id
                    LEFT JOIN (
                        SELECT 
                            api_key_id, 
                            COUNT(*) as kv_count, 
                            SUM(LENGTH(value)) as kv_bytes
                        FROM `{$prefix}kv_store` 
                        GROUP BY api_key_id
                    ) kv ON kv.api_key_id = ak.id
                    ON DUPLICATE KEY UPDATE 
                        total_events = VALUES(total_events),
                        total_kv_pairs = VALUES(total_kv_pairs),
                        total_kv_bytes = VALUES(total_kv_bytes),
                        total_event_bytes = VALUES(total_event_bytes),
                        earliest_event = VALUES(earliest_event),
                        latest_event = VALUES(latest_event)
                ");
                
                $elapsed = round(microtime(true) - $startTime, 1);
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}api_key_stats`");
                $statsCount = (int)$stmt->fetch()['cnt'];
                echo "   ✓ Populated: {$statsCount} API key stat records ({$elapsed}s)\n";
                $migrations[] = "api_key_stats populated ({$statsCount} records)";
            } else {
                echo "   ⏭ No API keys to process\n";
            }
        } catch (PDOException $e) {
            echo "   ⚠ Warning: {$e->getMessage()}\n";
        }
        
        // Create event_key_stats table for common event keys
        echo "\n7. Creating event_key_stats table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}event_key_stats` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `event_key` VARCHAR(128) NOT NULL,
                    `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_event_key` (`api_key_id`, `event_key`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    INDEX `idx_occurrence_count` (`occurrence_count`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}event_key_stats\n";
            $migrations[] = 'event_key_stats table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}event_key_stats\n";
            }
        }
        
        // Create api_logs_stats table for pre-computed request statistics
        echo "\n8. Creating api_logs_stats table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}api_logs_stats` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `stat_date` DATE NOT NULL,
                    `total_requests` INT UNSIGNED NOT NULL DEFAULT 0,
                    `success_requests` INT UNSIGNED NOT NULL DEFAULT 0,
                    `error_requests` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_date` (`api_key_id`, `stat_date`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    INDEX `idx_stat_date` (`stat_date`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}api_logs_stats\n";
            $migrations[] = 'api_logs_stats table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}api_logs_stats\n";
            }
        }
        
        // Create api_logs_endpoint_stats table for endpoint usage tracking
        echo "\n9. Creating api_logs_endpoint_stats table...\n";
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}api_logs_endpoint_stats` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `api_key_id` INT UNSIGNED NOT NULL,
                    `endpoint` VARCHAR(255) NOT NULL,
                    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `last_request` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `unique_api_key_endpoint` (`api_key_id`, `endpoint`),
                    INDEX `idx_api_key_id` (`api_key_id`),
                    INDEX `idx_request_count` (`request_count`),
                    FOREIGN KEY (`api_key_id`) REFERENCES `{$prefix}api_keys`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            echo "   ✓ Created: {$prefix}api_logs_endpoint_stats\n";
            $migrations[] = 'api_logs_endpoint_stats table';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: {$e->getMessage()}\n";
            } else {
                echo "   ⏭ Already exists: {$prefix}api_logs_endpoint_stats\n";
            }
        }
        
        // Populate event_key_stats from existing events (sample-based for large datasets)
        echo "\n10. Populating event_key_stats...\n";
        try {
            if ($eventCount > 0) {
                echo "   ⏳ Analyzing event keys (sampling for large datasets)...\n";
                $startTime = microtime(true);
                
                // For each API key, sample recent events to build key statistics
                $stmt = $pdo->query("SELECT id FROM `{$prefix}api_keys`");
                $apiKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($apiKeys as $apiKeyId) {
                    // Get sample of recent events (limit to 1000 for performance)
                    $stmt = $pdo->prepare("
                        SELECT event_data 
                        FROM `{$prefix}events` 
                        WHERE api_key_id = ? 
                        ORDER BY id DESC 
                        LIMIT 1000
                    ");
                    $stmt->execute([$apiKeyId]);
                    $events = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (empty($events)) continue;
                    
                    // Count key occurrences
                    $keyCounts = [];
                    foreach ($events as $json) {
                        $data = json_decode($json, true);
                        if (is_array($data)) {
                            foreach (array_keys($data) as $key) {
                                $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
                            }
                        }
                    }
                    
                    // Insert top keys (limit to top 50 per API key)
                    arsort($keyCounts);
                    $topKeys = array_slice($keyCounts, 0, 50, true);
                    
                    foreach ($topKeys as $key => $count) {
                        $stmt = $pdo->prepare("
                            INSERT INTO `{$prefix}event_key_stats` (api_key_id, event_key, occurrence_count)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                occurrence_count = VALUES(occurrence_count),
                                last_seen = CURRENT_TIMESTAMP
                        ");
                        $stmt->execute([$apiKeyId, $key, $count]);
                    }
                }
                
                $elapsed = round(microtime(true) - $startTime, 1);
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}event_key_stats`");
                $keyStatsCount = (int)$stmt->fetch()['cnt'];
                echo "   ✓ Populated: {$keyStatsCount} event key records ({$elapsed}s)\n";
                $migrations[] = "event_key_stats populated ({$keyStatsCount} records)";
            } else {
                echo "   ⏭ No existing events to analyze\n";
            }
        } catch (PDOException $e) {
            echo "   ⚠ Warning: {$e->getMessage()}\n";
        }
        
        // Populate api_logs_stats from existing logs
        echo "\n11. Populating api_logs_stats...\n";
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}api_logs`");
            $logCount = (int)$stmt->fetch()['cnt'];
            
            if ($logCount > 0) {
                echo "   ⏳ Aggregating request statistics from {$logCount} logs...\n";
                $startTime = microtime(true);
                
                $pdo->exec("
                    INSERT INTO `{$prefix}api_logs_stats` (api_key_id, stat_date, total_requests, success_requests, error_requests)
                    SELECT 
                        api_key_id,
                        DATE(created_at) as stat_date,
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success_requests,
                        SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_requests
                    FROM `{$prefix}api_logs`
                    GROUP BY api_key_id, DATE(created_at)
                    ON DUPLICATE KEY UPDATE 
                        total_requests = VALUES(total_requests),
                        success_requests = VALUES(success_requests),
                        error_requests = VALUES(error_requests)
                ");
                
                $elapsed = round(microtime(true) - $startTime, 1);
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}api_logs_stats`");
                $statsCount = (int)$stmt->fetch()['cnt'];
                echo "   ✓ Populated: {$statsCount} daily log stat records ({$elapsed}s)\n";
                $migrations[] = "api_logs_stats populated ({$statsCount} records)";
            } else {
                echo "   ⏭ No existing logs to process\n";
            }
        } catch (PDOException $e) {
            echo "   ⚠ Warning: {$e->getMessage()}\n";
        }
        
        // Populate api_logs_endpoint_stats from existing logs
        echo "\n12. Populating api_logs_endpoint_stats...\n";
        try {
            if ($logCount > 0) {
                echo "   ⏳ Aggregating endpoint statistics...\n";
                $startTime = microtime(true);
                
                $pdo->exec("
                    INSERT INTO `{$prefix}api_logs_endpoint_stats` (api_key_id, endpoint, request_count, last_request)
                    SELECT 
                        api_key_id,
                        endpoint,
                        COUNT(*) as request_count,
                        MAX(created_at) as last_request
                    FROM `{$prefix}api_logs`
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    GROUP BY api_key_id, endpoint
                    ON DUPLICATE KEY UPDATE 
                        request_count = VALUES(request_count),
                        last_request = VALUES(last_request)
                ");
                
                $elapsed = round(microtime(true) - $startTime, 1);
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$prefix}api_logs_endpoint_stats`");
                $statsCount = (int)$stmt->fetch()['cnt'];
                echo "   ✓ Populated: {$statsCount} endpoint stat records ({$elapsed}s)\n";
                $migrations[] = "api_logs_endpoint_stats populated ({$statsCount} records)";
            } else {
                echo "   ⏭ No existing logs to process\n";
            }
        } catch (PDOException $e) {
            echo "   ⚠ Warning: {$e->getMessage()}\n";
        }
        
        echo "\n";
        $migrations[] = "Statistics tables added and populated";
    }
    
MIGRATE_PHP_STATS
        ;;
esac

case "$ACTION" in
    optimize)
        OPTIMIZE_ACTION="${2:-analyze}"
        cat >> "$MIGRATE_FILE" <<'MIGRATE_PHP_OPTIMIZE'
    // =====================================================
    // OPTIMIZE: Database analysis and optimization
    // =====================================================
    if ($action === 'optimize') {
        echo "📋 Database Optimization\n";
        echo "================================\n\n";
        
        // Get all tables with stats
        $stmt = $pdo->query("
            SELECT 
                TABLE_NAME as name,
                TABLE_ROWS as row_count,
                DATA_LENGTH as data_size,
                INDEX_LENGTH as index_size,
                DATA_LENGTH + INDEX_LENGTH as total_size,
                DATA_FREE as fragmented_space,
                ROW_FORMAT as row_format
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY total_size DESC
        ");
        $tables = $stmt->fetchAll();
        
        $totalDataSize = 0;
        $totalIndexSize = 0;
        $totalFragmented = 0;
        
        echo "📊 Current Table Sizes:\n";
        echo str_repeat("-", 100) . "\n";
        printf("%-40s %15s %12s %12s %12s %10s\n", 
            "Table", "Rows", "Data", "Index", "Total", "Fragmented");
        echo str_repeat("-", 100) . "\n";
        
        foreach ($tables as $table) {
            $totalDataSize += (int)$table['data_size'];
            $totalIndexSize += (int)$table['index_size'];
            $totalFragmented += (int)$table['fragmented_space'];
            
            printf("%-40s %15s %12s %12s %12s %10s\n",
                $table['name'],
                number_format((int)$table['row_count']),
                formatBytes((int)$table['data_size']),
                formatBytes((int)$table['index_size']),
                formatBytes((int)$table['total_size']),
                formatBytes((int)$table['fragmented_space'])
            );
        }
        
        echo str_repeat("-", 100) . "\n";
        printf("%-40s %15s %12s %12s %12s %10s\n",
            "TOTAL",
            "",
            formatBytes($totalDataSize),
            formatBytes($totalIndexSize),
            formatBytes($totalDataSize + $totalIndexSize),
            formatBytes($totalFragmented)
        );
        echo "\n\n";
        
        // Show available actions
        if ($subAction === 'analyze') {
            echo "⚠️  Actions available:\n";
            echo "   Run with: ./scripts/db-migrate.sh optimize [action]\n\n";
            echo "   compress      - Enable ROW_FORMAT=COMPRESSED on events table\n";
            echo "   full          - Compress + Optimize all tables\n";
            echo "   update_stats  - Update api_key_stats with accurate sizes\n";
            echo "\n";
        }
        
        // Handle optimization actions
        if ($subAction === 'compress' || $subAction === 'full') {
            $eventsTable = "{$prefix}events";
            echo "🔄 Enabling compression on {$eventsTable}...\n";
            
            try {
                $pdo->exec("ALTER TABLE `{$eventsTable}` ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8");
                echo "   ✅ Compression enabled\n";
                $migrations[] = "Events table compressed";
            } catch (PDOException $e) {
                echo "   ❌ Failed: {$e->getMessage()}\n";
            }
            echo "\n";
        }
        
        if ($subAction === 'full') {
            echo "🔄 Optimizing all tables...\n";
            foreach ($tables as $table) {
                $tableName = $table['name'];
                if ((int)$table['fragmented_space'] < 1048576) {
                    echo "  ⏭ {$tableName} (minimal fragmentation)\n";
                    continue;
                }
                
                echo "  ⏳ {$tableName}... ";
                try {
                    $pdo->exec("OPTIMIZE TABLE `{$tableName}`");
                    echo "✅ done\n";
                } catch (PDOException $e) {
                    echo "❌ {$e->getMessage()}\n";
                }
            }
            echo "\n";
            $migrations[] = "All tables optimized";
        }
        
        if ($subAction === 'update_stats') {
            echo "🔄 Updating api_key_stats with accurate sizes...\n";
            try {
                $pdo->exec("
                    INSERT INTO {$prefix}api_key_stats (api_key_id, total_events, total_event_bytes, earliest_event, latest_event)
                    SELECT 
                        api_key_id,
                        COUNT(*) as total_events,
                        SUM(LENGTH(event_data)) as total_event_bytes,
                        MIN(event_timestamp) as earliest_event,
                        MAX(event_timestamp) as latest_event
                    FROM {$prefix}events
                    GROUP BY api_key_id
                    ON DUPLICATE KEY UPDATE
                        total_events = VALUES(total_events),
                        total_event_bytes = VALUES(total_event_bytes),
                        earliest_event = VALUES(earliest_event),
                        latest_event = VALUES(latest_event),
                        updated_at = NOW()
                ");
                echo "  ✅ api_key_stats updated\n";
                $migrations[] = "API key stats updated";
            } catch (PDOException $e) {
                echo "  ❌ Failed: {$e->getMessage()}\n";
            }
            echo "\n";
        }
    }
    
MIGRATE_PHP_OPTIMIZE
        ;;
esac

# Add footer
cat >> "$MIGRATE_FILE" <<'MIGRATE_PHP_FOOTER'
    // Summary
    echo "================================\n";
    echo "✅ Migration completed!\n";
    echo "   Added: {$addedColumns} columns\n";
    echo "   Added: {$addedIndexes} indexes\n";
    
    if (!empty($migrations)) {
        echo "\nApplied migrations:\n";
        foreach ($migrations as $m) {
            echo "   • {$m}\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    http_response_code(500);
}

// Self-destruct
@unlink(__FILE__);
MIGRATE_PHP_FOOTER

# Replace token placeholder
sed -i '' "s/TOKEN_PLACEHOLDER/$MIGRATE_TOKEN/" "$MIGRATE_FILE"

echo "📤 Uploading migration script..."

# Create lftp script for upload
LFTP_SCRIPT=$(mktemp)
trap "rm -f $LFTP_SCRIPT $MIGRATE_FILE" EXIT

cat > "$LFTP_SCRIPT" <<EOF
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd $REMOTE_PATH
put $MIGRATE_FILE -o $MIGRATE_FILENAME
bye
EOF

if ! lftp -f "$LFTP_SCRIPT" 2>/dev/null; then
    echo "❌ Failed to upload migration script"
    exit 1
fi

echo "✅ Migration script uploaded"
echo ""
echo "🔄 Executing migration..."
echo ""

# Build URL with action and sub-action
MIGRATE_URL="https://$DOMAIN/$MIGRATE_FILENAME?token=$MIGRATE_TOKEN&action=$ACTION"
if [ "$ACTION" = "optimize" ] && [ -n "${2:-}" ]; then
    MIGRATE_URL="${MIGRATE_URL}&sub=$2"
fi

# Execute the migration via HTTP
RESPONSE=$(curl -s -w "\n%{http_code}" --max-time 3600 "$MIGRATE_URL")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "$BODY"
echo ""

if [ "$HTTP_CODE" -eq 200 ]; then
    echo "🎉 Migration completed successfully!"
else
    echo "⚠️  Migration returned HTTP $HTTP_CODE"
    
    # Try to clean up the file if it still exists
    echo ""
    echo "🧹 Attempting cleanup..."
    
    cat > "$LFTP_SCRIPT" <<EOF
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd $REMOTE_PATH
rm -f $MIGRATE_FILENAME
bye
EOF
    
    lftp -f "$LFTP_SCRIPT" 2>/dev/null || true
    echo "   Cleanup attempted"
fi

echo ""
