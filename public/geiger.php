<?php

declare(strict_types=1);

/**
 * Berlin Radiation Monitor
 * 
 * Interactive µSv radiation measurements dashboard - Geiger counter data for Berlin.
 * Uses D3.js for interactive data visualization.
 */

require_once __DIR__ . '/config.php';

// ========================================
// CONFIGURATION - Hardcode your API key here
// ========================================
const API_KEY = 'dcbb01ccd49660c6eba608e999891a794dfac573b634e9a3d76fd9426f9e1931';
// ========================================

// Security headers
addSecurityHeaders();

// Initialize default values for template variables
$apiKeyName = 'Unknown';
$rangeLabel = 'All Time';
$range = 'all';
$startDate = new DateTime('-1 year');
$endDate = new DateTime();
$dailyUsv = 0.0;
$bananaEquivalent = 0.0;
$radiationStats = ['current_cpm' => 0, 'current_usvh' => 0, 'avg_cpm' => 0, 'avg_usvh' => 0, 'min_cpm' => 0, 'min_usvh' => 0, 'max_cpm' => 0, 'max_usvh' => 0];
$periodEventCount = 0;
$stats = ['total_events' => 0, 'earliest_event' => null, 'latest_event' => null];
$trend = 0;
$avgEventsPerDay = 0.0;
$groupBy = 'DAY';
$timelineData = [];
$error = null;

try {
    $config = loadConfig();
    $pdo = getDatabaseConnection($config);
    $prefix = getDbPrefix($config);
    
    // Validate API key and get its ID
    $stmt = $pdo->prepare("SELECT id, name, email FROM {$prefix}api_keys WHERE api_key = ?");
    $stmt->execute([API_KEY]);
    $apiKeyData = $stmt->fetch();
    
    if (!$apiKeyData) {
        throw new Exception("Invalid API key. Please configure a valid API key in this file.");
    }
    
    $apiKeyId = (int)$apiKeyData['id'];
    $apiKeyName = $apiKeyData['name'] ?: 'Unnamed Key';
    
    // Get event statistics from pre-computed stats table (O(1) instead of scanning 9M rows)
    $stmt = $pdo->prepare("
        SELECT total_events, earliest_event, latest_event
        FROM {$prefix}api_key_stats 
        WHERE api_key_id = ?
    ");
    $stmt->execute([$apiKeyId]);
    $stats = $stmt->fetch();
    
    // Fallback to direct query if stats not available
    if (!$stats || $stats['total_events'] === null) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_events,
                MIN(event_timestamp) as earliest_event,
                MAX(event_timestamp) as latest_event
            FROM {$prefix}events 
            WHERE api_key_id = ?
        ");
        $stmt->execute([$apiKeyId]);
        $stats = $stmt->fetch();
    }
    
    // Get time range from request
    $range = $_GET['range'] ?? 'all';
    $customStart = $_GET['start'] ?? null;
    $customEnd = $_GET['end'] ?? null;
    
    // Calculate date range
    $now = new DateTime();
    $endDate = clone $now;
    
    switch ($range) {
        case 'today':
            $startDate = new DateTime('today');
            $groupBy = 'HOUR';
            $dateFormat = 'H:00';
            $rangeLabel = 'Today';
            break;
        case '24h':
            $startDate = (clone $now)->modify('-24 hours');
            $groupBy = 'HOUR';
            $dateFormat = 'H:00';
            $rangeLabel = 'Last 24 Hours';
            break;
        case '7d':
            $startDate = (clone $now)->modify('-7 days');
            $groupBy = 'DAY';
            $dateFormat = 'M d';
            $rangeLabel = 'Last 7 Days';
            break;
        case '30d':
            $startDate = (clone $now)->modify('-30 days');
            $groupBy = 'DAY';
            $dateFormat = 'M d';
            $rangeLabel = 'Last 30 Days';
            break;
        case 'quarter':
            $startDate = (clone $now)->modify('-3 months');
            $groupBy = 'WEEK';
            $dateFormat = 'M d';
            $rangeLabel = 'Last Quarter';
            break;
        case 'year':
            $startDate = (clone $now)->modify('-1 year');
            $groupBy = 'MONTH';
            $dateFormat = 'M Y';
            $rangeLabel = 'Last Year';
            break;
        case 'all':
            $startDate = $stats['earliest_event'] ? new DateTime($stats['earliest_event']) : (clone $now)->modify('-1 year');
            $groupBy = 'MONTH';
            $dateFormat = 'M Y';
            $rangeLabel = 'All Time';
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                // Validate date format (YYYY-MM-DD or ISO 8601)
                if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?/', $customStart) ||
                    !preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?/', $customEnd)) {
                    throw new Exception("Invalid date format. Use YYYY-MM-DD.");
                }
                $startDate = new DateTime($customStart);
                $endDate = new DateTime($customEnd);
                $daysDiff = $startDate->diff($endDate)->days;
                
                if ($daysDiff <= 1) {
                    $groupBy = 'HOUR';
                    $dateFormat = 'H:00';
                } elseif ($daysDiff <= 31) {
                    $groupBy = 'DAY';
                    $dateFormat = 'M d';
                } elseif ($daysDiff <= 90) {
                    $groupBy = 'WEEK';
                    $dateFormat = 'M d';
                } else {
                    $groupBy = 'MONTH';
                    $dateFormat = 'M Y';
                }
                $rangeLabel = 'Custom Range';
            } else {
                $startDate = $stats['earliest_event'] ? new DateTime($stats['earliest_event']) : (clone $now)->modify('-1 year');
                $groupBy = 'MONTH';
                $dateFormat = 'M Y';
                $rangeLabel = 'All Time';
            }
            break;
        default:
            $startDate = $stats['earliest_event'] ? new DateTime($stats['earliest_event']) : (clone $now)->modify('-1 year');
            $groupBy = 'MONTH';
            $dateFormat = 'M Y';
            $rangeLabel = 'All Time';
    }
    
    // Fetch aggregated event data - OPTIMIZED for 9M+ events
    // Strategy: Use pre-computed daily stats table when grouping by DAY or coarser
    // Only fall back to raw events for HOUR grouping (smaller dataset)
    
    $timelineData = [];
    
    if ($groupBy === 'DAY') {
        // Use pre-computed daily stats (O(days) instead of O(events))
        $stmt = $pdo->prepare("
            SELECT 
                stat_date as period,
                event_count as count,
                CASE WHEN cpm_count > 0 THEN sum_cpm / cpm_count ELSE NULL END as avg_cpm,
                CASE WHEN usvh_count > 0 THEN sum_usvh / usvh_count ELSE NULL END as avg_usvh,
                CASE WHEN cpm_count > 0 THEN sum_cpm / cpm_count ELSE NULL END as mean_cpm,
                CASE WHEN usvh_count > 0 THEN sum_usvh / usvh_count ELSE NULL END as mean_usvh,
                min_cpm, max_cpm, min_usvh, max_usvh
            FROM {$prefix}event_stats
            WHERE api_key_id = ? AND stat_date >= ? AND stat_date <= ?
            ORDER BY stat_date ASC
        ");
        $stmt->execute([$apiKeyId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        $timelineData = $stmt->fetchAll();
        
    } elseif ($groupBy === 'WEEK') {
        // Aggregate daily stats into weeks
        $stmt = $pdo->prepare("
            SELECT 
                DATE(DATE_SUB(stat_date, INTERVAL WEEKDAY(stat_date) DAY)) as period,
                SUM(event_count) as count,
                CASE WHEN SUM(cpm_count) > 0 THEN SUM(sum_cpm) / SUM(cpm_count) ELSE NULL END as avg_cpm,
                CASE WHEN SUM(usvh_count) > 0 THEN SUM(sum_usvh) / SUM(usvh_count) ELSE NULL END as avg_usvh,
                CASE WHEN SUM(cpm_count) > 0 THEN SUM(sum_cpm) / SUM(cpm_count) ELSE NULL END as mean_cpm,
                CASE WHEN SUM(usvh_count) > 0 THEN SUM(sum_usvh) / SUM(usvh_count) ELSE NULL END as mean_usvh,
                MIN(min_cpm) as min_cpm, MAX(max_cpm) as max_cpm,
                MIN(min_usvh) as min_usvh, MAX(max_usvh) as max_usvh
            FROM {$prefix}event_stats
            WHERE api_key_id = ? AND stat_date >= ? AND stat_date <= ?
            GROUP BY DATE(DATE_SUB(stat_date, INTERVAL WEEKDAY(stat_date) DAY))
            ORDER BY period ASC
        ");
        $stmt->execute([$apiKeyId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        $timelineData = $stmt->fetchAll();
        
    } elseif ($groupBy === 'MONTH') {
        // Aggregate daily stats into months
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(stat_date, '%Y-%m-01') as period,
                SUM(event_count) as count,
                CASE WHEN SUM(cpm_count) > 0 THEN SUM(sum_cpm) / SUM(cpm_count) ELSE NULL END as avg_cpm,
                CASE WHEN SUM(usvh_count) > 0 THEN SUM(sum_usvh) / SUM(usvh_count) ELSE NULL END as avg_usvh,
                CASE WHEN SUM(cpm_count) > 0 THEN SUM(sum_cpm) / SUM(cpm_count) ELSE NULL END as mean_cpm,
                CASE WHEN SUM(usvh_count) > 0 THEN SUM(sum_usvh) / SUM(usvh_count) ELSE NULL END as mean_usvh,
                MIN(min_cpm) as min_cpm, MAX(max_cpm) as max_cpm,
                MIN(min_usvh) as min_usvh, MAX(max_usvh) as max_usvh
            FROM {$prefix}event_stats
            WHERE api_key_id = ? AND stat_date >= ? AND stat_date <= ?
            GROUP BY DATE_FORMAT(stat_date, '%Y-%m-01')
            ORDER BY period ASC
        ");
        $stmt->execute([$apiKeyId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        $timelineData = $stmt->fetchAll();
        
    } else {
        // HOUR grouping - try generated columns first, fallback to DATE_FORMAT
        // For hourly data, we're typically looking at 24-168 hours max, so this is manageable
        try {
            // Try using generated column (faster if exists)
            $stmt = $pdo->prepare("
                SELECT 
                    event_hour as period,
                    COUNT(*) as count,
                    AVG(cpm) as avg_cpm,
                    AVG(usvh) as avg_usvh,
                    AVG(cpm) as mean_cpm,
                    AVG(usvh) as mean_usvh,
                    MIN(cpm) as min_cpm, MAX(cpm) as max_cpm,
                    MIN(usvh) as min_usvh, MAX(usvh) as max_usvh
                FROM {$prefix}events
                WHERE api_key_id = ? AND event_timestamp >= ? AND event_timestamp <= ?
                GROUP BY event_hour
                ORDER BY event_hour ASC
            ");
            $stmt->execute([
                $apiKeyId,
                $startDate->format('Y-m-d H:i:s'),
                $endDate->format('Y-m-d H:i:s')
            ]);
            $timelineData = $stmt->fetchAll();
        } catch (PDOException $e) {
            // Fallback: use DATE_FORMAT and JSON extraction (slower but works without generated columns)
            $stmt = $pdo->prepare("
                SELECT 
                    DATE_FORMAT(event_timestamp, '%Y-%m-%d %H:00:00') as period,
                    COUNT(*) as count,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as avg_cpm,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as avg_usvh,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as mean_cpm,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as mean_usvh,
                    MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as min_cpm,
                    MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as max_cpm,
                    MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as min_usvh,
                    MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as max_usvh
                FROM {$prefix}events
                WHERE api_key_id = ? AND event_timestamp >= ? AND event_timestamp <= ?
                GROUP BY DATE_FORMAT(event_timestamp, '%Y-%m-%d %H:00:00')
                ORDER BY period ASC
            ");
            $stmt->execute([
                $apiKeyId,
                $startDate->format('Y-m-d H:i:s'),
                $endDate->format('Y-m-d H:i:s')
            ]);
            $timelineData = $stmt->fetchAll();
        }
    }
    
    // Fallback: If stats table is empty, use direct query (works without generated columns)
    if (empty($timelineData) && $groupBy !== 'HOUR') {
        $periodExpr = match($groupBy) {
            'DAY' => 'DATE(event_timestamp)',
            'WEEK' => 'DATE(DATE_SUB(event_timestamp, INTERVAL WEEKDAY(event_timestamp) DAY))',
            'MONTH' => "DATE_FORMAT(event_timestamp, '%Y-%m-01')",
        };
        
        $stmt = $pdo->prepare("
            SELECT 
                {$periodExpr} as period,
                COUNT(*) as count,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as avg_cpm,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as avg_usvh,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as mean_cpm,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as mean_usvh,
                MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as min_cpm,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as max_cpm,
                MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as min_usvh,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as max_usvh
            FROM {$prefix}events
            WHERE api_key_id = ? AND event_timestamp >= ? AND event_timestamp <= ?
            GROUP BY {$periodExpr}
            ORDER BY period ASC
        ");
        $stmt->execute([
            $apiKeyId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s')
        ]);
        $timelineData = $stmt->fetchAll();
    }
    
    // Calculate overall CPM and µSv/h statistics for the period
    // OPTIMIZED: Use pre-computed stats table (O(days) instead of O(9M events))
    $stmt = $pdo->prepare("
        SELECT 
            CASE WHEN SUM(cpm_count) > 0 THEN SUM(sum_cpm) / SUM(cpm_count) ELSE NULL END as avg_cpm,
            CASE WHEN SUM(usvh_count) > 0 THEN SUM(sum_usvh) / SUM(usvh_count) ELSE NULL END as avg_usvh,
            MIN(min_cpm) as min_cpm,
            MAX(max_cpm) as max_cpm,
            MIN(min_usvh) as min_usvh,
            MAX(max_usvh) as max_usvh
        FROM {$prefix}event_stats
        WHERE api_key_id = ? AND stat_date >= ? AND stat_date <= ?
    ");
    $stmt->execute([
        $apiKeyId,
        $startDate->format('Y-m-d'),
        $endDate->format('Y-m-d')
    ]);
    $radiationStats = $stmt->fetch();
    
    // Fallback: If stats table is empty, use JSON extraction (works without generated columns)
    if (!$radiationStats || $radiationStats['avg_cpm'] === null) {
        $stmt = $pdo->prepare("
            SELECT 
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as avg_cpm,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as avg_usvh,
                MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as min_cpm,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.cpm')) AS DECIMAL(10,4))) as max_cpm,
                MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as min_usvh,
                MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.usvh')) AS DECIMAL(10,6))) as max_usvh
            FROM {$prefix}events 
            WHERE api_key_id = ? 
            AND event_timestamp >= ? 
            AND event_timestamp <= ?
        ");
        $stmt->execute([
            $apiKeyId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s')
        ]);
        $radiationStats = $stmt->fetch();
    }
    
    // Get events count for the selected period - OPTIMIZED using stats table
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(event_count), 0) as count
        FROM {$prefix}event_stats
        WHERE api_key_id = ? AND stat_date >= ? AND stat_date <= ?
    ");
    $stmt->execute([
        $apiKeyId,
        $startDate->format('Y-m-d'),
        $endDate->format('Y-m-d')
    ]);
    $periodEventCount = (int)$stmt->fetch()['count'];
    
    // Fallback if stats table is empty
    if ($periodEventCount === 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM {$prefix}events 
            WHERE api_key_id = ? 
            AND event_timestamp >= ? 
            AND event_timestamp <= ?
        ");
        $stmt->execute([
            $apiKeyId,
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s')
        ]);
        $periodEventCount = (int)$stmt->fetch()['count'];
    }
    
    // Get events per day average for the period
    $daysDiff = max(1, $startDate->diff($endDate)->days);
    $avgEventsPerDay = round($periodEventCount / $daysDiff, 1);
    
    // Calculate daily µSv exposure and Banana Equivalent Dose (BED)
    // 1 BED ≈ 0.1 µSv (https://en.wikipedia.org/wiki/Banana_equivalent_dose)
    $medianUsvh = $radiationStats['avg_usvh'] ? (float)$radiationStats['avg_usvh'] : null;
    $dailyUsv = $medianUsvh !== null ? $medianUsvh * 24 : null;
    $bananaEquivalent = $dailyUsv !== null ? $dailyUsv / 0.1 : null;
    
    // Calculate trend (compare to previous period) - OPTIMIZED using stats table
    $prevStartDate = (clone $startDate)->modify("-{$daysDiff} days");
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(event_count), 0) as count
        FROM {$prefix}event_stats
        WHERE api_key_id = ? AND stat_date >= ? AND stat_date < ?
    ");
    $stmt->execute([
        $apiKeyId,
        $prevStartDate->format('Y-m-d'),
        $startDate->format('Y-m-d')
    ]);
    $prevPeriodCount = (int)$stmt->fetch()['count'];
    
    $trend = 0;
    if ($prevPeriodCount > 0) {
        $trend = round((($periodEventCount - $prevPeriodCount) / $prevPeriodCount) * 100, 1);
    } elseif ($periodEventCount > 0) {
        $trend = 100;
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Format number with K/M suffix
function formatNumber(int $num): string {
    if ($num >= 1000000) {
        return round($num / 1000000, 1) . 'M';
    } elseif ($num >= 1000) {
        return round($num / 1000, 1) . 'K';
    }
    return (string)$num;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#030712">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>☢️ Berlin Radiation Monitor | µSv Measurements</title>
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/fonts/fonts.css">
    <style>
        
        :root {
            --bg-deep: #030712;
            --bg-surface: rgba(17, 24, 39, 0.8);
            --bg-elevated: rgba(31, 41, 55, 0.6);
            --border: rgba(75, 85, 99, 0.3);
            --border-highlight: rgba(99, 102, 241, 0.5);
            
            --text-primary: #f9fafb;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            
            --accent-primary: #6366f1;
            --accent-secondary: #8b5cf6;
            --accent-gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
            
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            
            --chart-primary: #6366f1;
            --chart-secondary: #8b5cf6;
            --chart-gradient-start: rgba(99, 102, 241, 0.4);
            --chart-gradient-end: rgba(99, 102, 241, 0.02);
            
            /* CPM Chart Colors - Radioactive Yellow/Orange */
            --cpm-primary: #f59e0b;
            --cpm-secondary: #fbbf24;
            --cpm-gradient-start: rgba(245, 158, 11, 0.4);
            --cpm-gradient-end: rgba(245, 158, 11, 0.02);
            --cpm-glow: 0 0 20px rgba(245, 158, 11, 0.4);
            
            /* µSv/h Chart Colors - Gamma Green */
            --usvh-primary: #10b981;
            --usvh-secondary: #34d399;
            --usvh-gradient-start: rgba(16, 185, 129, 0.4);
            --usvh-gradient-end: rgba(16, 185, 129, 0.02);
            --usvh-glow: 0 0 20px rgba(16, 185, 129, 0.4);
            
            /* Range area color */
            --range-color: rgba(148, 163, 184, 0.2);
            
            --glow: 0 0 60px rgba(99, 102, 241, 0.3);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            
            --radius: 16px;
            --radius-sm: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-deep);
            color: var(--text-primary);
            min-height: 100vh;
            min-height: 100dvh;
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        
        /* Animated Background */
        .bg-pattern {
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(99, 102, 241, 0.15), transparent),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(139, 92, 246, 0.1), transparent),
                radial-gradient(ellipse 40% 30% at 0% 80%, rgba(168, 85, 247, 0.08), transparent);
            pointer-events: none;
            z-index: 0;
        }
        
        .grid-overlay {
            position: fixed;
            inset: 0;
            background-image: 
                linear-gradient(rgba(99, 102, 241, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99, 102, 241, 0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
            z-index: 0;
        }
        
        /* Layout */
        .container {
            position: relative;
            z-index: 1;
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem 2rem 4rem;
            padding-bottom: calc(4rem + env(safe-area-inset-bottom));
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 3rem;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .brand {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .brand-icon {
            width: 48px;
            height: 48px;
            background: var(--accent-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--glow);
        }
        
        .brand-text h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        
        .brand-text p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .brand-subtitle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .brand-subtitle .badge {
            background: var(--bg-elevated);
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            color: var(--accent-primary);
        }
        
        .brand-subtitle .blog-link {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .brand-subtitle .blog-link:hover {
            color: var(--accent-primary);
        }
        
        /* Time Range Selector */
        .time-controls {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .range-tabs {
            display: flex;
            background: var(--bg-surface);
            border-radius: var(--radius);
            padding: 4px;
            gap: 2px;
            border: 1px solid var(--border);
            backdrop-filter: blur(20px);
        }
        
        .range-tab {
            padding: 0.75rem 1.25rem;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border-radius: calc(var(--radius) - 4px);
            transition: var(--transition);
            white-space: nowrap;
        }
        
        .range-tab:hover {
            color: var(--text-primary);
            background: var(--bg-elevated);
        }
        
        .range-tab.active {
            background: var(--accent-primary);
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }
        
        .custom-range {
            display: none;
            flex-direction: column;
            gap: 0.75rem;
            background: var(--bg-surface);
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            backdrop-filter: blur(20px);
        }
        
        .custom-range.visible {
            display: flex;
        }
        
        .custom-range > .btn-apply {
            align-self: flex-end;
        }
        
        .custom-range input {
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.875rem;
            outline: none;
            transition: var(--transition);
        }
        
        .custom-range input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
        
        .custom-range span {
            color: var(--text-muted);
        }
        
        .btn-apply {
            background: var(--accent-primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .btn-apply:hover {
            background: var(--accent-secondary);
            transform: translateY(-1px);
        }
        
        .year-chips {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .year-chip {
            padding: 0.4rem 0.75rem;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }
        
        .year-chip:hover {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
            transform: translateY(-1px);
        }
        
        .year-chip.active {
            background: var(--accent-primary);
            border-color: var(--accent-primary);
            color: white;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.4);
        }
        
        .date-inputs {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.75rem;
            backdrop-filter: blur(20px);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-gradient);
            opacity: 0;
            transition: var(--transition);
        }
        
        .stat-card:hover {
            border-color: var(--border-highlight);
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            background: var(--bg-elevated);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .stat-value {
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, var(--text-primary) 0%, var(--text-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        
        .stat-value-secondary {
            font-size: 1.5rem;
            font-weight: 500;
            color: var(--text-secondary);
            -webkit-text-fill-color: var(--text-secondary);
            background: none;
            opacity: 0.7;
        }
        
        .stat-trend {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .stat-trend.up {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
        }
        
        .stat-trend.down {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }
        
        .stat-trend.neutral {
            background: var(--bg-elevated);
            color: var(--text-muted);
        }
        
        .stat-footer {
            margin-top: 0.75rem;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        /* Chart Container */
        .chart-container {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(20px);
            position: relative;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .chart-title-badge {
            background: var(--accent-gradient);
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .chart-legend {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent-primary);
        }
        
        .legend-dot-cpm {
            background: var(--cpm-primary);
        }
        
        .legend-dot-usvh {
            background: var(--usvh-primary);
        }
        
        .legend-dot-range {
            background: var(--range-color);
            border: 1px dashed rgba(148, 163, 184, 0.5);
        }
        
        /* CPM Chart Specific */
        .chart-container-cpm {
            border-color: rgba(245, 158, 11, 0.2);
        }
        
        .chart-container-cpm:hover {
            border-color: rgba(245, 158, 11, 0.4);
        }
        
        .chart-badge-cpm {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
        }
        
        .chart-icon-cpm {
            font-size: 1.25rem;
        }
        
        .stat-icon-cpm {
            background: rgba(245, 158, 11, 0.15);
        }
        
        .stat-value-cpm {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* µSv/h Chart Specific */
        .chart-container-usvh {
            border-color: rgba(16, 185, 129, 0.2);
        }
        
        .chart-container-usvh:hover {
            border-color: rgba(16, 185, 129, 0.4);
        }
        
        .chart-badge-usvh {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
        }
        
        .chart-icon-usvh {
            font-size: 1.25rem;
        }
        
        .stat-icon-usvh {
            background: rgba(16, 185, 129, 0.15);
        }
        
        .stat-value-usvh {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Banana/Daily Exposure Card */
        .stat-icon-banana {
            background: rgba(250, 204, 21, 0.15);
        }
        
        .stat-value-banana {
            background: linear-gradient(135deg, #facc15 0%, #eab308 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .banana-row {
            display: flex;
            gap: 2px;
            margin-top: 0.5rem;
            flex-wrap: wrap;
            font-size: 1rem;
            line-height: 1;
        }
        
        .banana-half {
            display: inline-block;
            width: 0.6em;
            overflow: hidden;
        }
        
        .stat-comparison {
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--border);
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .stat-comparison-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
        }
        
        .stat-comparison-label {
            color: var(--text-secondary);
        }
        
        .stat-comparison-value {
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-primary);
        }
        
        .chart-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        #cpm-chart,
        #usvh-chart {
            width: 100%;
            height: 400px;
        }
        
        .chart-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 400px;
            color: var(--text-muted);
            text-align: center;
        }
        
        .chart-empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        .chart-empty h3 {
            font-size: 1.25rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        /* Error State */
        .error-container {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: var(--radius);
            padding: 3rem;
            text-align: center;
            margin: 4rem auto;
            max-width: 600px;
        }
        
        .error-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .error-title {
            font-size: 1.5rem;
            color: var(--danger);
            margin-bottom: 0.5rem;
        }
        
        .error-message {
            color: var(--text-secondary);
        }
        
        /* Tooltip */
        .tooltip {
            position: absolute;
            background: var(--bg-deep);
            border: 1px solid var(--border-highlight);
            padding: 1rem;
            border-radius: var(--radius-sm);
            pointer-events: none;
            z-index: 9999;
            box-shadow: var(--shadow-xl), 0 0 30px rgba(0, 0, 0, 0.5);
            min-width: 160px;
            backdrop-filter: blur(10px);
        }
        
        .tooltip-date {
            color: var(--text-secondary);
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .tooltip-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .tooltip-label {
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .animate-in {
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .container {
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .time-controls {
                align-items: stretch;
            }
            
            .range-tabs {
                overflow-x: auto;
                justify-content: flex-start;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                -ms-overflow-style: none;
            }
            
            .range-tabs::-webkit-scrollbar {
                display: none;
            }
            
            .custom-range {
                flex-wrap: wrap;
            }
            
            .year-chips {
                justify-content: center;
            }
            
            .date-inputs {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .custom-range > .btn-apply {
                align-self: center;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.25rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
            
            .stat-value-secondary {
                font-size: 1.1rem;
            }
            
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.1rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
            }
            
            .stat-footer {
                font-size: 0.7rem;
            }
            
            .stat-comparison {
                font-size: 0.65rem;
            }
            
            #cpm-chart,
            #usvh-chart {
                height: 280px;
            }
            
            .chart-container {
                padding: 1.25rem;
                margin-bottom: 1rem;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
                margin-bottom: 1.25rem;
            }
            
            .chart-title {
                font-size: 1rem;
                flex-wrap: wrap;
            }
            
            .chart-title-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }
            
            .chart-controls {
                width: 100%;
                justify-content: flex-start;
            }
            
            .chart-legend {
                flex-wrap: wrap;
                gap: 0.75rem;
            }
            
            .legend-item {
                font-size: 0.75rem;
            }
            
            .range-tabs {
                padding: 3px;
            }
            
            .range-tab {
                padding: 0.6rem 0.875rem;
                font-size: 0.8rem;
                min-height: 44px;
            }
            
            .banana-row {
                font-size: 0.85rem;
            }
            
            .brand-text h1 {
                font-size: 1.35rem;
            }
            
            .brand-text p {
                font-size: 0.8rem;
            }
            
            .brand-icon {
                width: 42px;
                height: 42px;
                font-size: 1.25rem;
            }
            
            .brand-subtitle {
                font-size: 0.8rem;
                flex-wrap: wrap;
                gap: 0.35rem;
            }
            
            .year-chips {
                gap: 0.375rem;
            }
            
            .year-chip {
                padding: 0.5rem 0.75rem;
                min-height: 44px;
                display: flex;
                align-items: center;
            }
            
            .date-inputs {
                width: 100%;
            }
            
            .date-inputs input {
                flex: 1;
                min-width: 0;
                min-height: 44px;
            }
            
            .btn-apply {
                min-height: 44px;
                font-size: 0.9rem;
            }
            
            .tooltip {
                padding: 0.75rem;
                min-width: 140px;
                font-size: 0.85rem;
            }
            
            .tooltip-value {
                font-size: 1.25rem;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 0.75rem;
                padding-bottom: 2rem;
            }
            
            .header {
                margin-bottom: 1.5rem;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-header {
                margin-bottom: 0.5rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
            
            .stat-value-secondary {
                font-size: 1.25rem;
            }
            
            .brand-logo {
                gap: 0.75rem;
            }
            
            .brand-text h1 {
                font-size: 1.15rem;
            }
            
            .brand-text p {
                font-size: 0.75rem;
            }
            
            .brand-icon {
                width: 38px;
                height: 38px;
                font-size: 1.1rem;
                border-radius: 10px;
            }
            
            .brand-subtitle .badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }
            
            .range-tabs {
                border-radius: 12px;
                gap: 1px;
            }
            
            .range-tab {
                padding: 0.5rem 0.65rem;
                font-size: 0.7rem;
                border-radius: 10px;
            }
            
            .custom-range {
                padding: 0.875rem;
                border-radius: 12px;
            }
            
            .year-chip {
                font-size: 0.7rem;
                padding: 0.4rem 0.65rem;
            }
            
            #cpm-chart,
            #usvh-chart {
                height: 220px;
            }
            
            .chart-container {
                padding: 1rem;
                border-radius: 12px;
            }
            
            .chart-header {
                margin-bottom: 1rem;
            }
            
            .chart-title {
                font-size: 0.9rem;
                gap: 0.5rem;
            }
            
            .chart-icon-cpm,
            .chart-icon-usvh {
                font-size: 1rem;
            }
            
            .legend-item {
                font-size: 0.7rem;
            }
            
            .legend-dot {
                width: 8px;
                height: 8px;
            }
            
            .chart-empty {
                height: 220px;
            }
            
            .chart-empty-icon {
                font-size: 3rem;
            }
            
            .chart-empty h3 {
                font-size: 1rem;
            }
            
            .error-container {
                padding: 2rem 1.5rem;
                margin: 2rem 0.5rem;
            }
            
            .error-icon {
                font-size: 3rem;
            }
            
            .error-title {
                font-size: 1.25rem;
            }
        }
        
        /* Extra small devices */
        @media (max-width: 360px) {
            .container {
                padding: 0.5rem;
            }
            
            .brand-text h1 {
                font-size: 1rem;
            }
            
            .range-tab {
                padding: 0.45rem 0.5rem;
                font-size: 0.65rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
            
            .stat-card {
                padding: 0.875rem;
            }
            
            .chart-container {
                padding: 0.875rem;
            }
            
            #cpm-chart,
            #usvh-chart {
                height: 200px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .range-tab,
            .year-chip,
            .btn-apply {
                min-height: 44px;
            }
            
            .stat-card:hover {
                transform: none;
            }
            
            .stat-card:active {
                transform: scale(0.98);
            }
        }
        
        /* Landscape phone optimization */
        @media (max-height: 500px) and (orientation: landscape) {
            .container {
                padding: 0.75rem 1rem;
            }
            
            .header {
                margin-bottom: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 0.75rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            #cpm-chart,
            #usvh-chart {
                height: 200px;
            }
            
            .chart-container {
                padding: 1rem;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>
    <div class="grid-overlay"></div>
    
    <div class="container">
        <?php if (isset($error)): ?>
            <div class="error-container animate-in">
                <div class="error-icon">⚠️</div>
                <h2 class="error-title">Configuration Required</h2>
                <p class="error-message"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php else: ?>
            <!-- Header -->
            <header class="header animate-in">
                <div class="brand">
                    <div class="brand-logo">
                        <div class="brand-icon">☢️</div>
                        <div class="brand-text">
                            <h1>Berlin Radiation Monitor</h1>
                            <p>µSv Geiger Counter Measurements</p>
                        </div>
                    </div>
                    <div class="brand-subtitle">
                        <span class="badge"><?= htmlspecialchars($apiKeyName) ?></span>
                        <span>•</span>
                        <span><?= $rangeLabel ?></span>
                        <span>•</span>
                        <a href="https://github.com/kibotu" target="_blank" rel="noopener noreferrer" class="blog-link" title="Visit GitHub profile">📖 GitHub</a>
                    </div>
                </div>
                
                <div class="time-controls">
                    <div class="range-tabs">
                        <a href="?range=today" class="range-tab <?= $range === 'today' ? 'active' : '' ?>">Today</a>
                        <a href="?range=24h" class="range-tab <?= $range === '24h' ? 'active' : '' ?>">24h</a>
                        <a href="?range=7d" class="range-tab <?= $range === '7d' ? 'active' : '' ?>">7 Days</a>
                        <a href="?range=30d" class="range-tab <?= $range === '30d' ? 'active' : '' ?>">30 Days</a>
                        <a href="?range=quarter" class="range-tab <?= $range === 'quarter' ? 'active' : '' ?>">Quarter</a>
                        <a href="?range=year" class="range-tab <?= $range === 'year' ? 'active' : '' ?>">Year</a>
                        <a href="?range=all" class="range-tab <?= $range === 'all' ? 'active' : '' ?>">All Time</a>
                        <button type="button" class="range-tab <?= $range === 'custom' ? 'active' : '' ?>" onclick="toggleCustomRange()">Custom</button>
                    </div>
                    
                    <form class="custom-range <?= $range === 'custom' ? 'visible' : '' ?>" id="customRangeForm" action="" method="GET">
                        <input type="hidden" name="range" value="custom">
                        <div class="year-chips">
                            <?php for ($year = 2022; $year <= (int)date('Y'); $year++): ?>
                                <button type="button" class="year-chip" data-year="<?= $year ?>"><?= $year ?></button>
                            <?php endfor; ?>
                        </div>
                        <div class="date-inputs">
                            <input type="datetime-local" name="start" id="startDate" value="<?= $customStart ?? $startDate->format('Y-m-d\TH:i') ?>">
                            <span>to</span>
                            <input type="datetime-local" name="end" id="endDate" value="<?= $customEnd ?? $endDate->format('Y-m-d\TH:i') ?>">
                        </div>
                        <button type="submit" class="btn-apply">Apply</button>
                    </form>
                </div>
            </header>
            
            <!-- Stats Grid - ordered by most interesting first -->
            <div class="stats-grid">
                <?php 
                // Berlin typical background: ~0.08-0.12 µSv/h → ~2.0-2.9 µSv/day → ~20-29 bananas/day
                $berlinTypicalUsvh = 0.10;
                $berlinDailyUsv = $berlinTypicalUsvh * 24;
                $berlinBananas = $berlinDailyUsv / 0.1;
                ?>
                
                <!-- 1. Daily Exposure (Bananas) - Most interesting visualization -->
                <div class="stat-card animate-in delay-1">
                    <div class="stat-header">
                        <span class="stat-label">Daily Exposure</span>
                        <div class="stat-icon stat-icon-banana">🍌</div>
                    </div>
                    <div class="stat-value stat-value-banana"><?= $dailyUsv !== null ? number_format($dailyUsv, 2) : '—' ?></div>
                    <div class="stat-footer">
                        <?php if ($bananaEquivalent !== null): ?>
                            ≈ <?= number_format($bananaEquivalent, 1) ?> bananas/day
                            <div class="banana-row">
                                <?php 
                                // Visualize µSv value (not banana equivalent) - floor to nearest 0.5
                                $displayValue = floor($dailyUsv * 2) / 2;
                                $fullBananas = (int)floor($displayValue);
                                $hasHalf = ($displayValue - $fullBananas) >= 0.5;
                                
                                for ($i = 0; $i < $fullBananas; $i++): ?>
                                    <span>🍌</span>
                                <?php endfor; 
                                
                                if ($hasHalf): ?>
                                    <span class="banana-half">🍌</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            µSv per day
                        <?php endif; ?>
                    </div>
                    <div class="stat-comparison">
                        <div class="stat-comparison-row">
                            <span class="stat-comparison-label">Berlin typical:</span>
                            <span class="stat-comparison-value"><?= number_format($berlinDailyUsv, 1) ?> µSv/day (<?= number_format($berlinBananas, 0) ?>🍌)</span>
                        </div>
                        <?php if ($dailyUsv !== null): ?>
                        <div class="stat-comparison-row">
                            <span class="stat-comparison-label">vs. typical:</span>
                            <span class="stat-comparison-value" style="color: <?= $dailyUsv > $berlinDailyUsv ? 'var(--warning)' : 'var(--success)' ?>">
                                <?= $dailyUsv > $berlinDailyUsv ? '+' : '' ?><?= number_format((($dailyUsv / $berlinDailyUsv) - 1) * 100, 0) ?>%
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 2. Average µSv/h - Primary radiation metric -->
                <div class="stat-card animate-in delay-1">
                    <div class="stat-header">
                        <span class="stat-label">Average µSv/h</span>
                        <div class="stat-icon stat-icon-usvh">🔬</div>
                    </div>
                    <div class="stat-value stat-value-usvh"><?= $radiationStats['avg_usvh'] ? number_format((float)$radiationStats['avg_usvh'], 3) : '—' ?></div>
                    <div class="stat-footer">
                        <?php if ($radiationStats['min_usvh'] && $radiationStats['max_usvh']): ?>
                            Range: <?= number_format((float)$radiationStats['min_usvh'], 3) ?> – <?= number_format((float)$radiationStats['max_usvh'], 3) ?>
                        <?php else: ?>
                            microsieverts per hour
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 3. Average CPM - Secondary radiation metric -->
                <div class="stat-card animate-in delay-2">
                    <div class="stat-header">
                        <span class="stat-label">Average CPM</span>
                        <div class="stat-icon stat-icon-cpm">☢️</div>
                    </div>
                    <div class="stat-value stat-value-cpm"><?= $radiationStats['avg_cpm'] ? round((float)$radiationStats['avg_cpm'], 1) : '—' ?></div>
                    <div class="stat-footer">
                        <?php if ($radiationStats['min_cpm'] && $radiationStats['max_cpm']): ?>
                            Range: <?= round((float)$radiationStats['min_cpm'], 1) ?> – <?= round((float)$radiationStats['max_cpm'], 1) ?>
                        <?php else: ?>
                            counts per minute
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 4. Events - Period / All-time -->
                <div class="stat-card animate-in delay-2">
                    <div class="stat-header">
                        <span class="stat-label">Events</span>
                        <div class="stat-icon">📈</div>
                    </div>
                    <div class="stat-value"><?= formatNumber($periodEventCount) ?> <span class="stat-value-secondary">/ <?= formatNumber((int)$stats['total_events']) ?></span></div>
                    <?php if ($trend !== 0): ?>
                        <span class="stat-trend <?= $trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'neutral') ?>">
                            <?= $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→') ?> <?= abs($trend) ?>%
                        </span>
                    <?php else: ?>
                        <span class="stat-trend neutral">→ No change</span>
                    <?php endif; ?>
                    <div class="stat-footer">
                        period / all-time<?php if ($stats['earliest_event']): ?> (since <?= (new DateTime($stats['earliest_event']))->format('M j, Y') ?>)<?php endif; ?>
                    </div>
                </div>
                
                <!-- 5. Daily Average -->
                <div class="stat-card animate-in delay-3">
                    <div class="stat-header">
                        <span class="stat-label">Daily Average</span>
                        <div class="stat-icon">📅</div>
                    </div>
                    <div class="stat-value"><?= $avgEventsPerDay ?></div>
                    <div class="stat-footer">events per day</div>
                </div>
                
            </div>
            
            <!-- µSv/h Chart -->
            <div class="chart-container chart-container-usvh animate-in delay-4">
                <div class="chart-header">
                    <h2 class="chart-title">
                        <span class="chart-icon-usvh">🔬</span>
                        µSv/h (Microsieverts per Hour)
                        <span class="chart-title-badge chart-badge-usvh"><?= $rangeLabel ?></span>
                    </h2>
                    <div class="chart-controls">
                        <div class="chart-legend">
                            <div class="legend-item">
                                <div class="legend-dot legend-dot-usvh"></div>
                                <span>Average µSv/h</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot legend-dot-range"></div>
                                <span>Min/Max Range</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($timelineData)): ?>
                    <div class="chart-empty">
                        <div class="chart-empty-icon">🔬</div>
                        <h3>No µSv/h Data</h3>
                        <p>No radiation data recorded for the selected time range.</p>
                    </div>
                <?php else: ?>
                    <div id="usvh-chart"></div>
                <?php endif; ?>
            </div>
            
            <!-- CPM Chart -->
            <div class="chart-container chart-container-cpm animate-in delay-5">
                <div class="chart-header">
                    <h2 class="chart-title">
                        <span class="chart-icon-cpm">☢️</span>
                        CPM (Counts Per Minute)
                        <span class="chart-title-badge chart-badge-cpm"><?= $rangeLabel ?></span>
                    </h2>
                    <div class="chart-controls">
                        <div class="chart-legend">
                            <div class="legend-item">
                                <div class="legend-dot legend-dot-cpm"></div>
                                <span>Average CPM</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot legend-dot-range"></div>
                                <span>Min/Max Range</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($timelineData)): ?>
                    <div class="chart-empty">
                        <div class="chart-empty-icon">☢️</div>
                        <h3>No CPM Data</h3>
                        <p>No radiation data recorded for the selected time range.</p>
                    </div>
                <?php else: ?>
                    <div id="cpm-chart"></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="/js/d3.v7.min.js"></script>
    <script>
        // Toggle custom date range picker
        function toggleCustomRange() {
            const form = document.getElementById('customRangeForm');
            form.classList.toggle('visible');
        }
        
        // Year chip functionality
        document.querySelectorAll('.year-chip').forEach(chip => {
            chip.addEventListener('click', function() {
                const year = parseInt(this.dataset.year);
                const startDate = document.getElementById('startDate');
                const endDate = document.getElementById('endDate');
                
                // Set start to January 1st of the year
                startDate.value = `${year}-01-01T00:00`;
                
                // Set end to December 31st of the year (or now if current/future year)
                const now = new Date();
                const currentYear = now.getFullYear();
                
                if (year >= currentYear) {
                    // For current or future years, use current date/time
                    const pad = n => n.toString().padStart(2, '0');
                    endDate.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
                } else {
                    // For past years, use December 31st 23:59
                    endDate.value = `${year}-12-31T23:59`;
                }
                
                // Update active state
                document.querySelectorAll('.year-chip').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        
        <?php if (!isset($error) && !empty($timelineData)): ?>
        // Current grouping level for zoom functionality
        const currentGroupBy = '<?= $groupBy ?>';
        
        // Zoom into a specific time range based on clicked data point
        function zoomToRange(date, groupBy) {
            // Can't zoom further than hourly
            if (groupBy === 'HOUR') return;
            
            const pad = n => n.toString().padStart(2, '0');
            let startDate, endDate;
            
            if (groupBy === 'MONTH') {
                // Clicking a month → show the whole month (daily view)
                const year = date.getFullYear();
                const month = date.getMonth();
                startDate = new Date(year, month, 1);
                endDate = new Date(year, month + 1, 0, 23, 59, 59); // Last day of month
            } else if (groupBy === 'WEEK') {
                // Clicking a week → show that week (daily view)
                const dayOfWeek = date.getDay();
                const mondayOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek; // Adjust to Monday
                startDate = new Date(date);
                startDate.setDate(date.getDate() + mondayOffset);
                startDate.setHours(0, 0, 0, 0);
                endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + 6);
                endDate.setHours(23, 59, 59, 0);
            } else if (groupBy === 'DAY') {
                // Clicking a day → show that day (hourly view)
                startDate = new Date(date);
                startDate.setHours(0, 0, 0, 0);
                endDate = new Date(date);
                endDate.setHours(23, 59, 59, 0);
            }
            
            // Format dates for URL
            const formatDateParam = d => 
                `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            
            // Navigate to custom range
            const url = `?range=custom&start=${encodeURIComponent(formatDateParam(startDate))}&end=${encodeURIComponent(formatDateParam(endDate))}`;
            window.location.href = url;
        }
        
        // ================================
        // Shared Chart Utilities
        // ================================
        const chartUtils = {
            // Parse data dates
            parseDate: d3.timeParse('%Y-%m-%d %H:%M:%S'),
            parseSimpleDate: d3.timeParse('%Y-%m-%d'),
            
            // Check if mobile
            isMobile() {
                return window.innerWidth <= 768;
            },
            
            // Check if small mobile
            isSmallMobile() {
                return window.innerWidth <= 480;
            },
            
            // Get responsive margins
            getMargins() {
                if (this.isSmallMobile()) {
                    return { top: 15, right: 10, bottom: 45, left: 45 };
                }
                if (this.isMobile()) {
                    return { top: 15, right: 15, bottom: 45, left: 55 };
                }
                return { top: 20, right: 20, bottom: 50, left: 65 };
            },
            
            // Get responsive dot size
            getDotSize(dataLength) {
                if (this.isSmallMobile()) return dataLength > 20 ? 2.5 : 3.5;
                if (this.isMobile()) return dataLength > 25 ? 3 : 4;
                return dataLength > 30 ? 4 : 5;
            },
            
            // Get responsive hover dot size
            getHoverDotSize(dataLength) {
                if (this.isSmallMobile()) return dataLength > 20 ? 4 : 5;
                if (this.isMobile()) return dataLength > 25 ? 5 : 6;
                return dataLength > 30 ? 6 : 8;
            },
            
            // Get responsive line width
            getLineWidth() {
                return this.isSmallMobile() ? 2 : 2.5;
            },
            
            // Get responsive tick count
            getTickCount() {
                if (this.isSmallMobile()) return 4;
                if (this.isMobile()) return 5;
                return 6;
            },
            
            // Smart tick interval based on date range
            getTickInterval(startDate, endDate) {
                const days = (endDate - startDate) / (1000 * 60 * 60 * 24);
                const isMobile = this.isMobile();
                
                if (days <= 2) return d3.timeHour.every(isMobile ? 6 : 4);
                if (days <= 7) return d3.timeDay.every(isMobile ? 2 : 1);
                if (days <= 31) return d3.timeDay.every(Math.ceil(days / (isMobile ? 5 : 8)));
                if (days <= 90) return d3.timeWeek.every(isMobile ? 2 : 1);
                if (days <= 365) return d3.timeMonth.every(isMobile ? 2 : 1);
                return d3.timeMonth.every(Math.ceil(days / (isMobile ? 180 : 365)));
            },
            
            // Smart date format based on range
            getDateFormat(startDate, endDate) {
                const days = (endDate - startDate) / (1000 * 60 * 60 * 24);
                const isMobile = this.isMobile();
                
                if (days <= 2) return d3.timeFormat('%H:%M');
                if (days <= 31) return d3.timeFormat(isMobile ? '%d' : '%b %d');
                if (days <= 365) return d3.timeFormat(isMobile ? '%b' : '%b %d');
                return d3.timeFormat(isMobile ? '%b %y' : '%b %Y');
            }
        };
        
        // ================================
        // CPM Chart Visualization
        // ================================
        (function() {
            const rawData = <?= json_encode($timelineData) ?>;
            
            // Parse and prepare data
            const data = rawData.map(d => ({
                date: chartUtils.parseDate(d.period) || chartUtils.parseSimpleDate(d.period),
                value: d.avg_cpm ? +d.avg_cpm : null,
                min: d.min_cpm ? +d.min_cpm : null,
                max: d.max_cpm ? +d.max_cpm : null
            })).filter(d => d.value !== null && d.date !== null)
              .sort((a, b) => a.date - b.date);
            
            if (data.length === 0) {
                d3.select('#cpm-chart').html(`
                    <div class="chart-empty">
                        <div class="chart-empty-icon">☢️</div>
                        <h3>No CPM Data</h3>
                        <p>No CPM values recorded in events.</p>
                    </div>
                `);
                return;
            }
            
            // Responsive dimensions
            const container = document.getElementById('cpm-chart');
            const rect = container.getBoundingClientRect();
            const margin = chartUtils.getMargins();
            const width = Math.max(rect.width - margin.left - margin.right, 200);
            const chartHeight = chartUtils.isSmallMobile() ? 220 : (chartUtils.isMobile() ? 280 : 400);
            const height = chartHeight - margin.top - margin.bottom;
            
            // Create SVG with clip path
            const svg = d3.select('#cpm-chart')
                .append('svg')
                .attr('width', width + margin.left + margin.right)
                .attr('height', height + margin.top + margin.bottom);
            
            // Clip path to contain chart content
            svg.append('defs')
                .append('clipPath')
                .attr('id', 'cpm-clip')
                .append('rect')
                .attr('width', width)
                .attr('height', height);
            
            const g = svg.append('g')
                .attr('transform', `translate(${margin.left},${margin.top})`);
            
            // Scales - use data extent and apply .nice() for clean boundaries
            const xExtent = d3.extent(data, d => d.date);
            const x = d3.scaleTime()
                .domain(xExtent)
                .nice()
                .range([0, width]);
            
            const yMax = d3.max(data, d => d.max || d.value) * 1.1;
            const yMin = Math.max(0, d3.min(data, d => d.min || d.value) * 0.9);
            const y = d3.scaleLinear()
                .domain([yMin, yMax])
                .nice()
                .range([height, 0]);
            
            // Gradient
            const gradient = svg.select('defs')
                .append('linearGradient')
                .attr('id', 'cpmGradient')
                .attr('x1', '0%').attr('y1', '0%')
                .attr('x2', '0%').attr('y2', '100%');
            gradient.append('stop').attr('offset', '0%').attr('stop-color', '#f59e0b').attr('stop-opacity', 0.4);
            gradient.append('stop').attr('offset', '100%').attr('stop-color', '#f59e0b').attr('stop-opacity', 0.02);
            
            // Grid
            const tickInterval = chartUtils.getTickInterval(xExtent[0], xExtent[1]);
            
            g.append('g')
                .attr('class', 'grid')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(tickInterval).tickSize(-height).tickFormat(''))
                .call(g => g.select('.domain').remove())
                .call(g => g.selectAll('.tick line').attr('stroke', 'rgba(245, 158, 11, 0.08)').attr('stroke-dasharray', '4,4'));
            
            g.append('g')
                .attr('class', 'grid')
                .call(d3.axisLeft(y).ticks(6).tickSize(-width).tickFormat(''))
                .call(g => g.select('.domain').remove())
                .call(g => g.selectAll('.tick line').attr('stroke', 'rgba(245, 158, 11, 0.08)').attr('stroke-dasharray', '4,4'));
            
            // Chart content group with clipping
            const chartContent = g.append('g').attr('clip-path', 'url(#cpm-clip)');
            
            // Min/Max range area
            const rangeArea = d3.area()
                .x(d => x(d.date))
                .y0(d => y(d.min || d.value))
                .y1(d => y(d.max || d.value))
                .curve(d3.curveMonotoneX);
            
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'rgba(148, 163, 184, 0.12)')
                .attr('d', rangeArea)
                .style('opacity', 0)
                .transition().duration(800).style('opacity', 1);
            
            // Area under line
            const area = d3.area()
                .x(d => x(d.date))
                .y0(height)
                .y1(d => y(d.value))
                .curve(d3.curveMonotoneX);
            
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'url(#cpmGradient)')
                .attr('d', area)
                .style('opacity', 0)
                .transition().duration(800).style('opacity', 1);
            
            // Line
            const line = d3.line()
                .x(d => x(d.date))
                .y(d => y(d.value))
                .curve(d3.curveMonotoneX);
            
            // Glow effect
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'none')
                .attr('stroke', '#f59e0b')
                .attr('stroke-width', 8)
                .attr('filter', 'blur(8px)')
                .attr('opacity', 0.25)
                .attr('d', line);
            
            // Main line with animation
            const lineWidth = chartUtils.getLineWidth();
            const linePath = chartContent.append('path')
                .datum(data)
                .attr('fill', 'none')
                .attr('stroke', '#f59e0b')
                .attr('stroke-width', lineWidth)
                .attr('stroke-linecap', 'round')
                .attr('stroke-linejoin', 'round')
                .attr('d', line);
            
            const totalLength = linePath.node().getTotalLength();
            linePath
                .attr('stroke-dasharray', totalLength)
                .attr('stroke-dashoffset', totalLength)
                .transition().duration(1200).ease(d3.easeCubicOut)
                .attr('stroke-dashoffset', 0);
            
            // Axes
            const dateFormat = chartUtils.getDateFormat(xExtent[0], xExtent[1]);
            const isMobile = chartUtils.isMobile();
            const tickCount = chartUtils.getTickCount();
            const fontSize = isMobile ? '9px' : '11px';
            
            g.append('g')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(tickInterval).tickFormat(dateFormat))
                .call(g => g.select('.domain').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick line').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick text').attr('fill', '#9ca3af').attr('font-size', fontSize)
                    .attr('transform', isMobile ? 'rotate(-45)' : 'rotate(-35)')
                    .attr('text-anchor', 'end').attr('dy', '0.5em'));
            
            g.append('g')
                .call(d3.axisLeft(y).ticks(tickCount).tickFormat(d => d >= 1000 ? (d/1000).toFixed(1) + 'K' : d.toFixed(0)))
                .call(g => g.select('.domain').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick line').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick text').attr('fill', '#9ca3af').attr('font-size', fontSize));
            
            // Y-axis label
            g.append('text')
                .attr('transform', 'rotate(-90)')
                .attr('y', isMobile ? -35 : -50)
                .attr('x', -height / 2)
                .attr('fill', '#6b7280')
                .attr('font-size', isMobile ? '9px' : '11px')
                .attr('text-anchor', 'middle')
                .text('CPM');
            
            // Dots - responsive sizing
            const dotSize = chartUtils.getDotSize(data.length);
            const hoverDotSize = chartUtils.getHoverDotSize(data.length);
            
            const dots = chartContent.selectAll('.cpm-dot')
                .data(data)
                .enter()
                .append('circle')
                .attr('class', 'cpm-dot')
                .attr('cx', d => x(d.date))
                .attr('cy', d => y(d.value))
                .attr('r', 0)
                .attr('fill', '#f59e0b')
                .attr('stroke', '#030712')
                .attr('stroke-width', isMobile ? 1.5 : 2)
                .style('cursor', 'pointer');
            
            dots.transition()
                .delay((d, i) => 800 + i * (isMobile ? 15 : 30))
                .duration(200)
                .attr('r', dotSize);
            
            // Tooltip
            const tooltip = d3.select('.chart-container-cpm')
                .append('div')
                .attr('class', 'tooltip')
                .style('opacity', 0)
                .style('display', 'none');
            
            // Track if we should zoom on click (not after drag/long touch)
            let touchStartTime = 0;
            let touchMoved = false;
            
            dots.on('mouseover', function(event, d) {
                    d3.select(this).transition().duration(150).attr('r', hoverDotSize);
                    
                    tooltip.style('display', 'block').transition().duration(150).style('opacity', 1);
                    
                    let rangeHtml = d.min !== null && d.max !== null && d.min !== d.max 
                        ? `<div class="tooltip-label">Range: ${d.min.toFixed(1)} – ${d.max.toFixed(1)}</div>` : '';
                    
                    // Show zoom hint if can zoom deeper
                    const zoomHint = currentGroupBy !== 'HOUR' 
                        ? `<div class="tooltip-label" style="margin-top: 0.5rem; color: #6366f1; font-size: 0.7rem;">🔍 Click to zoom in</div>` : '';
                    
                    tooltip.html(`
                        <div class="tooltip-date">${dateFormat(d.date)}</div>
                        <div class="tooltip-value" style="color: #f59e0b">${d.value.toFixed(1)}</div>
                        <div class="tooltip-label">Average CPM</div>
                        ${rangeHtml}
                        ${zoomHint}
                    `);
                    
                    const tooltipRect = tooltip.node().getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    const clientX = event.clientX;
                    const clientY = event.clientY;
                    let left = clientX - containerRect.left - tooltipRect.width / 2;
                    let top = clientY - containerRect.top - tooltipRect.height - 15;
                    
                    if (left < 10) left = 10;
                    if (left + tooltipRect.width > containerRect.width - 10) left = containerRect.width - tooltipRect.width - 10;
                    if (top < 10) top = clientY - containerRect.top + 20;
                    
                    tooltip.style('left', left + 'px').style('top', top + 'px');
                })
                .on('mouseout', function() {
                    d3.select(this).transition().duration(150).attr('r', dotSize);
                    tooltip.transition().duration(150).style('opacity', 0)
                        .on('end', () => tooltip.style('display', 'none'));
                })
                .on('click', function(event, d) {
                    event.stopPropagation();
                    zoomToRange(d.date, currentGroupBy);
                })
                .on('touchstart', function(event, d) {
                    event.preventDefault();
                    touchStartTime = Date.now();
                    touchMoved = false;
                    d3.select(this).transition().duration(150).attr('r', hoverDotSize);
                    
                    tooltip.style('display', 'block').transition().duration(150).style('opacity', 1);
                    
                    let rangeHtml = d.min !== null && d.max !== null && d.min !== d.max 
                        ? `<div class="tooltip-label">Range: ${d.min.toFixed(1)} – ${d.max.toFixed(1)}</div>` : '';
                    
                    const zoomHint = currentGroupBy !== 'HOUR' 
                        ? `<div class="tooltip-label" style="margin-top: 0.5rem; color: #6366f1; font-size: 0.7rem;">🔍 Tap to zoom in</div>` : '';
                    
                    tooltip.html(`
                        <div class="tooltip-date">${dateFormat(d.date)}</div>
                        <div class="tooltip-value" style="color: #f59e0b">${d.value.toFixed(1)}</div>
                        <div class="tooltip-label">Average CPM</div>
                        ${rangeHtml}
                        ${zoomHint}
                    `);
                    
                    const tooltipRect = tooltip.node().getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    const clientX = event.touches[0].clientX;
                    const clientY = event.touches[0].clientY;
                    let left = clientX - containerRect.left - tooltipRect.width / 2;
                    let top = clientY - containerRect.top - tooltipRect.height - 15;
                    
                    if (left < 10) left = 10;
                    if (left + tooltipRect.width > containerRect.width - 10) left = containerRect.width - tooltipRect.width - 10;
                    if (top < 10) top = clientY - containerRect.top + 20;
                    
                    tooltip.style('left', left + 'px').style('top', top + 'px');
                })
                .on('touchmove', function() {
                    touchMoved = true;
                })
                .on('touchend', function(event, d) {
                    const touchDuration = Date.now() - touchStartTime;
                    d3.select(this).transition().duration(150).attr('r', dotSize);
                    tooltip.transition().duration(150).style('opacity', 0)
                        .on('end', () => tooltip.style('display', 'none'));
                    
                    // Only zoom on quick tap (< 300ms) without movement
                    if (!touchMoved && touchDuration < 300) {
                        zoomToRange(d.date, currentGroupBy);
                    }
                });
        })();
        
        // ================================
        // µSv/h Chart Visualization
        // ================================
        (function() {
            const rawData = <?= json_encode($timelineData) ?>;
            
            // Parse and prepare data
            const data = rawData.map(d => ({
                date: chartUtils.parseDate(d.period) || chartUtils.parseSimpleDate(d.period),
                value: d.avg_usvh ? +d.avg_usvh : null,
                min: d.min_usvh ? +d.min_usvh : null,
                max: d.max_usvh ? +d.max_usvh : null
            })).filter(d => d.value !== null && d.date !== null)
              .sort((a, b) => a.date - b.date);
            
            if (data.length === 0) {
                d3.select('#usvh-chart').html(`
                    <div class="chart-empty">
                        <div class="chart-empty-icon">🔬</div>
                        <h3>No µSv/h Data</h3>
                        <p>No µSv/h values recorded in events.</p>
                    </div>
                `);
                return;
            }
            
            // Responsive dimensions
            const container = document.getElementById('usvh-chart');
            const rect = container.getBoundingClientRect();
            const margin = chartUtils.getMargins();
            // Adjust left margin for µSv/h labels (more decimal places)
            margin.left = chartUtils.isSmallMobile() ? 50 : (chartUtils.isMobile() ? 60 : 70);
            const width = Math.max(rect.width - margin.left - margin.right, 200);
            const chartHeight = chartUtils.isSmallMobile() ? 220 : (chartUtils.isMobile() ? 280 : 400);
            const height = chartHeight - margin.top - margin.bottom;
            
            // Create SVG with clip path
            const svg = d3.select('#usvh-chart')
                .append('svg')
                .attr('width', width + margin.left + margin.right)
                .attr('height', height + margin.top + margin.bottom);
            
            // Clip path to contain chart content
            svg.append('defs')
                .append('clipPath')
                .attr('id', 'usvh-clip')
                .append('rect')
                .attr('width', width)
                .attr('height', height);
            
            const g = svg.append('g')
                .attr('transform', `translate(${margin.left},${margin.top})`);
            
            // Scales - use data extent and apply .nice() for clean boundaries
            const xExtent = d3.extent(data, d => d.date);
            const x = d3.scaleTime()
                .domain(xExtent)
                .nice()
                .range([0, width]);
            
            const yMax = d3.max(data, d => d.max || d.value) * 1.1;
            const yMin = Math.max(0, d3.min(data, d => d.min || d.value) * 0.9);
            const y = d3.scaleLinear()
                .domain([yMin, yMax])
                .nice()
                .range([height, 0]);
            
            // Gradient
            const gradient = svg.select('defs')
                .append('linearGradient')
                .attr('id', 'usvhGradient')
                .attr('x1', '0%').attr('y1', '0%')
                .attr('x2', '0%').attr('y2', '100%');
            gradient.append('stop').attr('offset', '0%').attr('stop-color', '#10b981').attr('stop-opacity', 0.4);
            gradient.append('stop').attr('offset', '100%').attr('stop-color', '#10b981').attr('stop-opacity', 0.02);
            
            // Grid
            const tickInterval = chartUtils.getTickInterval(xExtent[0], xExtent[1]);
            
            g.append('g')
                .attr('class', 'grid')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(tickInterval).tickSize(-height).tickFormat(''))
                .call(g => g.select('.domain').remove())
                .call(g => g.selectAll('.tick line').attr('stroke', 'rgba(16, 185, 129, 0.08)').attr('stroke-dasharray', '4,4'));
            
            g.append('g')
                .attr('class', 'grid')
                .call(d3.axisLeft(y).ticks(6).tickSize(-width).tickFormat(''))
                .call(g => g.select('.domain').remove())
                .call(g => g.selectAll('.tick line').attr('stroke', 'rgba(16, 185, 129, 0.08)').attr('stroke-dasharray', '4,4'));
            
            // Chart content group with clipping
            const chartContent = g.append('g').attr('clip-path', 'url(#usvh-clip)');
            
            // Min/Max range area
            const rangeArea = d3.area()
                .x(d => x(d.date))
                .y0(d => y(d.min || d.value))
                .y1(d => y(d.max || d.value))
                .curve(d3.curveMonotoneX);
            
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'rgba(148, 163, 184, 0.12)')
                .attr('d', rangeArea)
                .style('opacity', 0)
                .transition().duration(800).style('opacity', 1);
            
            // Area under line
            const area = d3.area()
                .x(d => x(d.date))
                .y0(height)
                .y1(d => y(d.value))
                .curve(d3.curveMonotoneX);
            
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'url(#usvhGradient)')
                .attr('d', area)
                .style('opacity', 0)
                .transition().duration(800).style('opacity', 1);
            
            // Line
            const line = d3.line()
                .x(d => x(d.date))
                .y(d => y(d.value))
                .curve(d3.curveMonotoneX);
            
            // Glow effect
            chartContent.append('path')
                .datum(data)
                .attr('fill', 'none')
                .attr('stroke', '#10b981')
                .attr('stroke-width', 8)
                .attr('filter', 'blur(8px)')
                .attr('opacity', 0.25)
                .attr('d', line);
            
            // Main line with animation
            const lineWidth = chartUtils.getLineWidth();
            const linePath = chartContent.append('path')
                .datum(data)
                .attr('fill', 'none')
                .attr('stroke', '#10b981')
                .attr('stroke-width', lineWidth)
                .attr('stroke-linecap', 'round')
                .attr('stroke-linejoin', 'round')
                .attr('d', line);
            
            const totalLength = linePath.node().getTotalLength();
            linePath
                .attr('stroke-dasharray', totalLength)
                .attr('stroke-dashoffset', totalLength)
                .transition().duration(1200).ease(d3.easeCubicOut)
                .attr('stroke-dashoffset', 0);
            
            // Axes
            const dateFormat = chartUtils.getDateFormat(xExtent[0], xExtent[1]);
            const isMobile = chartUtils.isMobile();
            const isSmallMobile = chartUtils.isSmallMobile();
            const tickCount = chartUtils.getTickCount();
            const fontSize = isMobile ? '9px' : '11px';
            
            g.append('g')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(tickInterval).tickFormat(dateFormat))
                .call(g => g.select('.domain').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick line').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick text').attr('fill', '#9ca3af').attr('font-size', fontSize)
                    .attr('transform', isMobile ? 'rotate(-45)' : 'rotate(-35)')
                    .attr('text-anchor', 'end').attr('dy', '0.5em'));
            
            g.append('g')
                .call(d3.axisLeft(y).ticks(tickCount).tickFormat(d => isSmallMobile ? d.toFixed(2) : d.toFixed(3)))
                .call(g => g.select('.domain').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick line').attr('stroke', '#374151'))
                .call(g => g.selectAll('.tick text').attr('fill', '#9ca3af').attr('font-size', fontSize));
            
            // Y-axis label
            g.append('text')
                .attr('transform', 'rotate(-90)')
                .attr('y', isSmallMobile ? -35 : (isMobile ? -45 : -55))
                .attr('x', -height / 2)
                .attr('fill', '#6b7280')
                .attr('font-size', isMobile ? '9px' : '11px')
                .attr('text-anchor', 'middle')
                .text('µSv/h');
            
            // Dots - responsive sizing
            const dotSize = chartUtils.getDotSize(data.length);
            const hoverDotSize = chartUtils.getHoverDotSize(data.length);
            
            const dots = chartContent.selectAll('.usvh-dot')
                .data(data)
                .enter()
                .append('circle')
                .attr('class', 'usvh-dot')
                .attr('cx', d => x(d.date))
                .attr('cy', d => y(d.value))
                .attr('r', 0)
                .attr('fill', '#10b981')
                .attr('stroke', '#030712')
                .attr('stroke-width', isMobile ? 1.5 : 2)
                .style('cursor', 'pointer');
            
            dots.transition()
                .delay((d, i) => 800 + i * (isMobile ? 15 : 30))
                .duration(200)
                .attr('r', dotSize);
            
            // Tooltip
            const tooltip = d3.select('.chart-container-usvh')
                .append('div')
                .attr('class', 'tooltip')
                .style('opacity', 0)
                .style('display', 'none');
            
            // Track if we should zoom on click (not after drag/long touch)
            let touchStartTime = 0;
            let touchMoved = false;
            
            dots.on('mouseover', function(event, d) {
                    d3.select(this).transition().duration(150).attr('r', hoverDotSize);
                    
                    tooltip.style('display', 'block').transition().duration(150).style('opacity', 1);
                    
                    const decimals = isSmallMobile ? 3 : 4;
                    let rangeHtml = d.min !== null && d.max !== null && d.min !== d.max 
                        ? `<div class="tooltip-label">Range: ${d.min.toFixed(decimals)} – ${d.max.toFixed(decimals)}</div>` : '';
                    
                    // Show zoom hint if can zoom deeper
                    const zoomHint = currentGroupBy !== 'HOUR' 
                        ? `<div class="tooltip-label" style="margin-top: 0.5rem; color: #6366f1; font-size: 0.7rem;">🔍 Click to zoom in</div>` : '';
                    
                    tooltip.html(`
                        <div class="tooltip-date">${dateFormat(d.date)}</div>
                        <div class="tooltip-value" style="color: #10b981">${d.value.toFixed(decimals)}</div>
                        <div class="tooltip-label">Average µSv/h</div>
                        ${rangeHtml}
                        ${zoomHint}
                    `);
                    
                    const tooltipRect = tooltip.node().getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    const clientX = event.clientX;
                    const clientY = event.clientY;
                    let left = clientX - containerRect.left - tooltipRect.width / 2;
                    let top = clientY - containerRect.top - tooltipRect.height - 15;
                    
                    if (left < 10) left = 10;
                    if (left + tooltipRect.width > containerRect.width - 10) left = containerRect.width - tooltipRect.width - 10;
                    if (top < 10) top = clientY - containerRect.top + 20;
                    
                    tooltip.style('left', left + 'px').style('top', top + 'px');
                })
                .on('mouseout', function() {
                    d3.select(this).transition().duration(150).attr('r', dotSize);
                    tooltip.transition().duration(150).style('opacity', 0)
                        .on('end', () => tooltip.style('display', 'none'));
                })
                .on('click', function(event, d) {
                    event.stopPropagation();
                    zoomToRange(d.date, currentGroupBy);
                })
                .on('touchstart', function(event, d) {
                    event.preventDefault();
                    touchStartTime = Date.now();
                    touchMoved = false;
                    d3.select(this).transition().duration(150).attr('r', hoverDotSize);
                    
                    tooltip.style('display', 'block').transition().duration(150).style('opacity', 1);
                    
                    const decimals = isSmallMobile ? 3 : 4;
                    let rangeHtml = d.min !== null && d.max !== null && d.min !== d.max 
                        ? `<div class="tooltip-label">Range: ${d.min.toFixed(decimals)} – ${d.max.toFixed(decimals)}</div>` : '';
                    
                    const zoomHint = currentGroupBy !== 'HOUR' 
                        ? `<div class="tooltip-label" style="margin-top: 0.5rem; color: #6366f1; font-size: 0.7rem;">🔍 Tap to zoom in</div>` : '';
                    
                    tooltip.html(`
                        <div class="tooltip-date">${dateFormat(d.date)}</div>
                        <div class="tooltip-value" style="color: #10b981">${d.value.toFixed(decimals)}</div>
                        <div class="tooltip-label">Average µSv/h</div>
                        ${rangeHtml}
                        ${zoomHint}
                    `);
                    
                    const tooltipRect = tooltip.node().getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    const clientX = event.touches[0].clientX;
                    const clientY = event.touches[0].clientY;
                    let left = clientX - containerRect.left - tooltipRect.width / 2;
                    let top = clientY - containerRect.top - tooltipRect.height - 15;
                    
                    if (left < 10) left = 10;
                    if (left + tooltipRect.width > containerRect.width - 10) left = containerRect.width - tooltipRect.width - 10;
                    if (top < 10) top = clientY - containerRect.top + 20;
                    
                    tooltip.style('left', left + 'px').style('top', top + 'px');
                })
                .on('touchmove', function() {
                    touchMoved = true;
                })
                .on('touchend', function(event, d) {
                    const touchDuration = Date.now() - touchStartTime;
                    d3.select(this).transition().duration(150).attr('r', dotSize);
                    tooltip.transition().duration(150).style('opacity', 0)
                        .on('end', () => tooltip.style('display', 'none'));
                    
                    // Only zoom on quick tap (< 300ms) without movement
                    if (!touchMoved && touchDuration < 300) {
                        zoomToRange(d.date, currentGroupBy);
                    }
                });
        })();
        <?php endif; ?>
    </script>
</body>
</html>

