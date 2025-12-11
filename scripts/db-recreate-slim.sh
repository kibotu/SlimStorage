#!/bin/bash
#
# Remote Database Recreate Script (Slim Version)
# ⚠️  WARNING: This script recreates schema but PRESERVES events!
# Recreates: API keys, sessions, rate limits, KV store, logs
# Preserves: Events table and all event data
# Usage: ./scripts/db-recreate-slim.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SECRETS_FILE="$PROJECT_ROOT/.secrets.yml"

echo "🗄️  Remote Database Recreate (Slim)"
echo ""
echo "⚠️  WARNING: This will recreate tables but PRESERVE events!"
echo "   Tables to recreate: API keys, sessions, rate limits, KV store, logs, stats"
echo "   Tables to preserve: Events and event data"
echo ""
read -p "Are you sure you want to continue? (type 'yes' to confirm): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
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
RECREATE_TOKEN=$(openssl rand -hex 32)

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

# Create temporary recreate PHP file
RECREATE_FILE=$(mktemp)
RECREATE_FILENAME="_recreate_slim_$(date +%s).php"

cat > "$RECREATE_FILE" <<'RECREATE_PHP'
<?php
/**
 * Database Recreate Script (Slim Version)
 * Recreates all tables except events - preserves all event data
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

require_once __DIR__ . '/config.php';

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);
    
    echo "🗄️  Database Recreation (Slim)\n";
    echo "================================\n\n";
    
    // Disable foreign key checks for dropping
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Drop non-event tables
    echo "Dropping non-event tables...\n";
    $tables = [
        'slimstore_event_aggregations',
        'slimstore_event_schemas', 
        'slimstore_event_stats',
        'slimstore_api_key_stats',
        'slimstore_api_logs',
        'slimstore_rate_limits',
        'slimstore_kv_store',
        'slimstore_sessions'
    ];
    
    // Drop dynamic aggregation tables (event_stats_hourly_*, event_stats_daily_*)
    $stmt = $pdo->query("SHOW TABLES LIKE 'slimstore_event_stats_%'");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $dynamicTable = $row[0];
        if (preg_match('/^slimstore_event_stats_(hourly|daily)_\d+$/', $dynamicTable)) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$dynamicTable`");
                echo "  ✓ Dropped: $dynamicTable\n";
            } catch (PDOException $e) {
                echo "  ⚠ Warning dropping $dynamicTable: {$e->getMessage()}\n";
            }
        }
    }
    
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "  ✓ Dropped: $table\n";
        } catch (PDOException $e) {
            echo "  ⚠ Warning dropping $table: {$e->getMessage()}\n";
        }
    }
    
    echo "\n⚠️  Backing up API keys before recreation...\n";
    
    // Backup existing API keys
    $stmt = $pdo->query("SELECT id, api_key, name, email, created_at, last_used_at FROM slimstore_api_keys");
    $apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  ✓ Backed up " . count($apiKeys) . " API keys\n";
    
    // Drop and recreate API keys table
    echo "\nRecreating API keys table...\n";
    $pdo->exec("DROP TABLE IF EXISTS `slimstore_api_keys`");
    echo "  ✓ Dropped: slimstore_api_keys\n";
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "\nCreating tables...\n";
    
    // Create slimstore_api_keys table
    $pdo->exec("
        CREATE TABLE `slimstore_api_keys` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key` VARCHAR(64) NOT NULL UNIQUE,
            `name` VARCHAR(64) NULL,
            `email` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL,
            INDEX `idx_api_key` (`api_key`),
            INDEX `idx_email` (`email`),
            INDEX `idx_email_created` (`email`, `created_at` DESC),
            INDEX `idx_email_last_used` (`email`, `last_used_at` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_api_keys\n";
    
    // Restore API keys
    echo "\nRestoring API keys...\n";
    $stmt = $pdo->prepare("
        INSERT INTO slimstore_api_keys (id, api_key, name, email, created_at, last_used_at)
        VALUES (:id, :api_key, :name, :email, :created_at, :last_used_at)
    ");
    foreach ($apiKeys as $key) {
        $stmt->execute($key);
    }
    echo "  ✓ Restored " . count($apiKeys) . " API keys\n";
    
    // Create slimstore_kv_store table
    $pdo->exec("
        CREATE TABLE `slimstore_kv_store` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `key` VARCHAR(255) NOT NULL,
            `value` MEDIUMTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key_key` (`api_key_id`, `key`),
            INDEX `idx_api_key_id` (`api_key_id`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_kv_store\n";
    
    // Create slimstore_sessions table
    $pdo->exec("
        CREATE TABLE `slimstore_sessions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(64) NOT NULL UNIQUE,
            `email` VARCHAR(255) NOT NULL,
            `photo_url` VARCHAR(512) NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_session_id` (`session_id`),
            INDEX `idx_expires_at` (`expires_at`),
            INDEX `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_sessions\n";
    
    // Create slimstore_rate_limits table
    $pdo->exec("
        CREATE TABLE `slimstore_rate_limits` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45) NOT NULL UNIQUE,
            `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_ip_address` (`ip_address`),
            INDEX `idx_window_start` (`window_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_rate_limits\n";

    // Create slimstore_api_logs table
    $pdo->exec("
        CREATE TABLE `slimstore_api_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `endpoint` VARCHAR(50) NOT NULL,
            `method` VARCHAR(10) NOT NULL,
            `status_code` SMALLINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_api_key_id` (`api_key_id`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_api_key_date` (`api_key_id`, `created_at`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_api_logs\n";
    
    echo "\n⚠️  Note: slimstore_events table was preserved with all data\n";
    
    // Create slimstore_event_stats table for pre-computed daily aggregates
    $pdo->exec("
        CREATE TABLE `slimstore_event_stats` (
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
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_event_stats\n";
    
    // Create slimstore_api_key_stats table for pre-computed API key statistics
    $pdo->exec("
        CREATE TABLE `slimstore_api_key_stats` (
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
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_api_key_stats\n";
    
    // Create slimstore_event_key_stats table for common event keys
    $pdo->exec("
        CREATE TABLE `slimstore_event_key_stats` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `event_key` VARCHAR(128) NOT NULL,
            `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key_event_key` (`api_key_id`, `event_key`),
            INDEX `idx_api_key_id` (`api_key_id`),
            INDEX `idx_occurrence_count` (`occurrence_count`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_event_key_stats\n";
    
    // Create slimstore_api_logs_stats table for pre-computed request statistics
    $pdo->exec("
        CREATE TABLE `slimstore_api_logs_stats` (
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
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_api_logs_stats\n";
    
    // Create slimstore_api_logs_endpoint_stats table for endpoint usage tracking
    $pdo->exec("
        CREATE TABLE `slimstore_api_logs_endpoint_stats` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `endpoint` VARCHAR(255) NOT NULL,
            `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_request` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key_endpoint` (`api_key_id`, `endpoint`),
            INDEX `idx_api_key_id` (`api_key_id`),
            INDEX `idx_request_count` (`request_count`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_api_logs_endpoint_stats\n";
    
    // Create slimstore_event_schemas table for user-defined event schemas
    $pdo->exec("
        CREATE TABLE `slimstore_event_schemas` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(64) NOT NULL,
            `field_type` ENUM('integer', 'bigint', 'float', 'double', 'string', 'boolean') NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key_field` (`api_key_id`, `field_name`),
            INDEX `idx_api_key_id` (`api_key_id`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_event_schemas\n";
    
    // Create slimstore_event_aggregations table for tracking aggregation status
    $pdo->exec("
        CREATE TABLE `slimstore_event_aggregations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `api_key_id` INT UNSIGNED NOT NULL,
            `aggregation_type` ENUM('hourly', 'daily') NOT NULL,
            `status` ENUM('pending', 'building', 'active', 'error') NOT NULL DEFAULT 'pending',
            `row_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_updated` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_api_key_agg` (`api_key_id`, `aggregation_type`),
            INDEX `idx_api_key_id` (`api_key_id`),
            FOREIGN KEY (`api_key_id`) REFERENCES `slimstore_api_keys`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✓ Created: slimstore_event_aggregations\n";
    
    // Rebuild stats from existing events
    echo "\n🔄 Rebuilding stats from existing events...\n";
    
    // Get event counts per API key
    $stmt = $pdo->query("
        SELECT 
            api_key_id,
            COUNT(*) as total_events,
            MIN(event_timestamp) as earliest_event,
            MAX(event_timestamp) as latest_event,
            SUM(JSON_LENGTH(event_data)) as total_event_bytes
        FROM slimstore_events
        GROUP BY api_key_id
    ");
    
    $eventStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Insert into api_key_stats
    $insertStmt = $pdo->prepare("
        INSERT INTO slimstore_api_key_stats 
        (api_key_id, total_events, earliest_event, latest_event, total_event_bytes)
        VALUES (:api_key_id, :total_events, :earliest_event, :latest_event, :total_event_bytes)
    ");
    
    foreach ($eventStats as $stats) {
        $insertStmt->execute([
            'api_key_id' => $stats['api_key_id'],
            'total_events' => $stats['total_events'],
            'earliest_event' => $stats['earliest_event'],
            'latest_event' => $stats['latest_event'],
            'total_event_bytes' => $stats['total_event_bytes'] ?? 0
        ]);
    }
    
    echo "  ✓ Rebuilt stats for " . count($eventStats) . " API keys\n";
    
    echo "\n✅ Database recreation (slim) completed successfully!\n";
    echo "   Non-event tables recreated, events preserved.\n";
    echo "   API keys and stats have been restored.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    http_response_code(500);
}

// Self-destruct
@unlink(__FILE__);
RECREATE_PHP

# Replace token placeholder
sed -i '' "s/TOKEN_PLACEHOLDER/$RECREATE_TOKEN/" "$RECREATE_FILE"

echo "📤 Uploading recreate script..."

# Create lftp script for upload
LFTP_SCRIPT=$(mktemp)
trap "rm -f $LFTP_SCRIPT $RECREATE_FILE" EXIT

cat > "$LFTP_SCRIPT" <<EOF
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd $REMOTE_PATH
put $RECREATE_FILE -o $RECREATE_FILENAME
bye
EOF

if ! lftp -f "$LFTP_SCRIPT" 2>/dev/null; then
    echo "❌ Failed to upload recreate script"
    exit 1
fi

echo "✅ Recreate script uploaded"
echo ""
echo "🔄 Executing database recreation (slim)..."
echo ""

# Execute the recreation via HTTP
RECREATE_URL="https://$DOMAIN/$RECREATE_FILENAME?token=$RECREATE_TOKEN"
RESPONSE=$(curl -s -w "\n%{http_code}" "$RECREATE_URL")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "$BODY"
echo ""

if [ "$HTTP_CODE" -eq 200 ]; then
    echo "🎉 Remote database recreation (slim) completed successfully!"
else
    echo "⚠️  Recreation returned HTTP $HTTP_CODE"
    
    # Try to clean up the file if it still exists
    echo ""
    echo "🧹 Attempting cleanup..."
    
    cat > "$LFTP_SCRIPT" <<EOF
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST
cd $REMOTE_PATH
rm -f $RECREATE_FILENAME
bye
EOF
    
    lftp -f "$LFTP_SCRIPT" 2>/dev/null || true
    echo "   Cleanup attempted"
fi

echo ""

