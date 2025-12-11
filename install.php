<?php
/**
 * SlimStorage Installer - One-File Installation & Update System
 * 
 * This standalone script downloads, installs, and configures SlimStorage.
 * 
 * Features:
 * - Downloads latest release from GitHub automatically
 * - Extracts and installs all files
 * - Guides you through configuration
 * - Creates .secrets.yml from a web form
 * - Tests database connection
 * - Initializes database schema
 * - Can also be used for updates
 * 
 * Requirements:
 * - PHP 8.1+
 * - MySQL 5.7+ or MariaDB 10.3+
 * - PDO MySQL extension
 * - cURL extension
 * - ZipArchive extension
 * 
 * Usage:
 * 1. Upload ONLY this file to your web server
 * 2. Navigate to it in your browser (e.g., https://yourdomain.com/install.php)
 * 3. Follow the on-screen instructions
 * 4. Delete this file after installation for security
 * 
 * For Updates:
 * - Upload this file again and add ?update=1 to the URL
 * - It will preserve your .secrets.yml and update all other files
 */

declare(strict_types=1);

// Configuration
const GITHUB_REPO = 'kibotu/SlimStorage';
const GITHUB_API_URL = 'https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest';
const INSTALL_DIR = __DIR__;
const SECRETS_FILE = INSTALL_DIR . '/.secrets.yml';
const PUBLIC_DIR = INSTALL_DIR . '/public';
const TEMP_DIR = INSTALL_DIR . '/temp_install';
const DOWNLOAD_TIMEOUT = 300; // 5 minutes

// Detect update mode
$isUpdateMode = isset($_GET['update']) && $_GET['update'] === '1';

// Security: Prevent access after installation is complete (unless updating)
if (file_exists(SECRETS_FILE) && !isset($_GET['force']) && !$isUpdateMode) {
    die('⚠️ Installation already complete. Delete .secrets.yml or add ?force=1 to reinstall, or ?update=1 to update.');
}

// Start session for multi-step process
session_start();

// Store update mode in session
if ($isUpdateMode) {
    $_SESSION['update_mode'] = true;
}

// Handle different installation steps
$step = $_GET['step'] ?? 'welcome';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SlimStorage Installer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: #333;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 100%;
            padding: 3rem;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1 {
            color: #667eea;
            margin-bottom: 0.5rem;
            font-size: 2.5rem;
            text-align: center;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 1rem;
        }

        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #999;
            transition: all 0.3s;
        }

        .step.active {
            background: #667eea;
            color: white;
            transform: scale(1.2);
        }

        .step.completed {
            background: #10b981;
            color: white;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .label-description {
            font-size: 0.875rem;
            color: #666;
            font-weight: normal;
            margin-top: 0.25rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
        }

        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6b7280;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-success {
            background: #10b981;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-danger {
            background: #ef4444;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }

        .alert-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
        }

        .alert-info {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e40af;
        }

        .requirements {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 6px;
            margin: 1.5rem 0;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .requirement-ok {
            color: #10b981;
        }

        .requirement-fail {
            color: #ef4444;
        }

        .code-block {
            background: #1f2937;
            color: #f3f4f6;
            padding: 1rem;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            overflow-x: auto;
            margin: 1rem 0;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
            }

            h1 {
                font-size: 2rem;
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
            }
        }

        .feature-list {
            list-style: none;
            margin: 1.5rem 0;
        }

        .feature-list li {
            padding: 0.5rem 0;
            padding-left: 2rem;
            position: relative;
        }

        .feature-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #10b981;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .collapsible {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
            cursor: pointer;
            user-select: none;
        }

        .collapsible:hover {
            background: #f3f4f6;
        }

        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .collapsible-content.active {
            max-height: 1000px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php
        // Route to appropriate step handler
        match ($step) {
            'welcome' => renderWelcomeStep(),
            'requirements' => renderRequirementsStep(),
            'download' => renderDownloadStep(),
            'configure' => renderConfigureStep(),
            'process' => processConfiguration(),
            'database' => renderDatabaseStep(),
            'complete' => renderCompleteStep(),
            default => renderWelcomeStep()
        };
        ?>
    </div>
</body>
</html>

<?php

// ============================================================================
// STEP 1: Welcome
// ============================================================================

function renderWelcomeStep(): void
{
    $isUpdate = $_SESSION['update_mode'] ?? false;
    ?>
    <h1>🔐 SlimStorage</h1>
    <p class="subtitle"><?= $isUpdate ? 'Update System' : 'Secure Key/Value Store & Event API' ?></p>

    <?php if ($isUpdate): ?>
        <div class="alert alert-info">
            <strong>Update Mode</strong><br>
            This wizard will download and install the latest version of SlimStorage while preserving your configuration.
        </div>
        
        <div class="alert alert-warning">
            <strong>⚠️ Before updating:</strong><br>
            • Your .secrets.yml will be preserved<br>
            • Database will NOT be modified<br>
            • All files will be updated to the latest version<br>
            • It's recommended to backup your database first
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <strong>Welcome to the SlimStorage installer!</strong><br>
            This wizard will download and set up your own SlimStorage instance.
        </div>

        <ul class="feature-list">
            <li>RESTful Key/Value Store with UUID-based keys</li>
            <li>Time-series Event API for IoT & Analytics</li>
            <li>Google OAuth authentication</li>
            <li>Rate limiting & security features</li>
            <li>Admin dashboard with analytics</li>
            <li>Multi-user support with isolated namespaces</li>
        </ul>

        <div class="alert alert-warning">
            <strong>⚠️ Before you begin:</strong><br>
            Make sure you have MySQL database credentials and a Google OAuth client ID ready.
        </div>
    <?php endif; ?>

    <div class="btn-group">
        <a href="?step=requirements" class="btn">Get Started →</a>
    </div>
    <?php
}

// ============================================================================
// STEP 2: Requirements Check
// ============================================================================

function renderRequirementsStep(): void
{
    $checks = checkRequirements();
    $allPassed = !in_array(false, array_column($checks, 'status'), true);

    ?>
    <h1>System Requirements</h1>
    <p class="subtitle">Checking your server configuration...</p>

    <div class="requirements">
        <?php foreach ($checks as $check): ?>
            <div class="requirement">
                <span class="<?= $check['status'] ? 'requirement-ok' : 'requirement-fail' ?>">
                    <?= $check['status'] ? '✓' : '✗' ?>
                </span>
                <span><?= htmlspecialchars($check['name']) ?></span>
                <?php if (!empty($check['message'])): ?>
                    <span style="color: #666; font-size: 0.875rem;">
                        (<?= htmlspecialchars($check['message']) ?>)
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($allPassed): ?>
        <div class="alert alert-success">
            ✓ All requirements met! You can proceed with the installation.
        </div>
        <div class="btn-group">
            <a href="?step=welcome" class="btn btn-secondary">← Back</a>
            <a href="?step=download" class="btn">Continue →</a>
        </div>
    <?php else: ?>
        <div class="alert alert-error">
            ✗ Some requirements are not met. Please fix the issues above before continuing.
        </div>
        <div class="btn-group">
            <a href="?step=welcome" class="btn btn-secondary">← Back</a>
            <a href="?step=requirements" class="btn">Recheck</a>
        </div>
    <?php endif; ?>
    <?php
}

function checkRequirements(): array
{
    $checks = [];

    // PHP Version
    $phpVersion = PHP_VERSION;
    $checks[] = [
        'name' => 'PHP Version',
        'status' => version_compare($phpVersion, '8.1.0', '>='),
        'message' => "Current: $phpVersion, Required: 8.1+"
    ];

    // PDO MySQL
    $checks[] = [
        'name' => 'PDO MySQL Extension',
        'status' => extension_loaded('pdo_mysql'),
        'message' => extension_loaded('pdo_mysql') ? 'Installed' : 'Not installed'
    ];

    // cURL
    $checks[] = [
        'name' => 'cURL Extension',
        'status' => extension_loaded('curl'),
        'message' => extension_loaded('curl') ? 'Installed' : 'Not installed'
    ];

    // JSON
    $checks[] = [
        'name' => 'JSON Extension',
        'status' => extension_loaded('json'),
        'message' => extension_loaded('json') ? 'Installed' : 'Not installed'
    ];

    // ZipArchive
    $checks[] = [
        'name' => 'ZipArchive Extension',
        'status' => class_exists('ZipArchive'),
        'message' => class_exists('ZipArchive') ? 'Installed' : 'Not installed (required for extracting releases)'
    ];

    // Write permissions
    $canWrite = is_writable(INSTALL_DIR);
    $checks[] = [
        'name' => 'Write Permissions',
        'status' => $canWrite,
        'message' => $canWrite ? 'Directory is writable' : 'Cannot write to directory'
    ];

    return $checks;
}

// ============================================================================
// STEP 3: Download and Extract Release
// ============================================================================

function renderDownloadStep(): void
{
    $isUpdate = $_SESSION['update_mode'] ?? false;
    
    ?>
    <h1><?= $isUpdate ? '📥 Downloading Update' : '📥 Downloading SlimStorage' ?></h1>
    <p class="subtitle">Fetching the latest release from GitHub...</p>

    <div id="download-status" class="requirements">
        <div class="requirement">
            <span id="status-fetch">⏳</span>
            <span>Fetching release information...</span>
        </div>
        <div class="requirement">
            <span id="status-download">⏳</span>
            <span>Downloading release package...</span>
        </div>
        <div class="requirement">
            <span id="status-extract">⏳</span>
            <span>Extracting files...</span>
        </div>
        <?php if (!$isUpdate): ?>
        <div class="requirement">
            <span id="status-schema">⏳</span>
            <span>Preparing database schema...</span>
        </div>
        <?php endif; ?>
    </div>

    <div id="download-progress" style="display: none;">
        <div class="progress-bar">
            <div class="progress-fill" id="progress-fill" style="width: 0%"></div>
        </div>
        <p id="progress-text" style="text-align: center; color: #666; margin-top: 0.5rem;"></p>
    </div>

    <div id="download-error" class="alert alert-error" style="display: none;">
        <strong>Download failed:</strong><br>
        <span id="error-message"></span>
    </div>

    <div id="download-success" class="alert alert-success" style="display: none;">
        ✓ SlimStorage downloaded and extracted successfully!
    </div>

    <div class="btn-group" id="action-buttons" style="display: none;">
        <a href="?step=requirements" class="btn btn-secondary">← Back</a>
        <a href="?step=configure" class="btn" id="continue-btn">Continue →</a>
    </div>

    <script>
        // Auto-start download
        window.addEventListener('DOMContentLoaded', function() {
            performDownload();
        });

        function updateStatus(id, status, text) {
            const elem = document.getElementById(id);
            if (elem) {
                elem.textContent = status;
                elem.className = status === '✓' ? 'requirement-ok' : (status === '✗' ? 'requirement-fail' : '');
            }
            if (text && elem && elem.nextElementSibling) {
                elem.nextElementSibling.textContent = text;
            }
        }

        function showError(message) {
            document.getElementById('download-error').style.display = 'block';
            document.getElementById('error-message').textContent = message;
            document.getElementById('action-buttons').style.display = 'flex';
        }

        function showSuccess() {
            document.getElementById('download-success').style.display = 'block';
            document.getElementById('action-buttons').style.display = 'flex';
        }

        async function performDownload() {
            try {
                // Step 1: Fetch release info
                updateStatus('status-fetch', '⏳', 'Fetching release information...');
                const response = await fetch('?step=download&action=fetch');
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to fetch release information');
                }
                
                updateStatus('status-fetch', '✓', 'Release information fetched');
                
                // Step 2: Download
                updateStatus('status-download', '⏳', 'Downloading release package...');
                const downloadResponse = await fetch('?step=download&action=download');
                const downloadData = await downloadResponse.json();
                
                if (!downloadData.success) {
                    throw new Error(downloadData.error || 'Failed to download release');
                }
                
                updateStatus('status-download', '✓', 'Release package downloaded (' + downloadData.size + ')');
                
                // Step 3: Extract
                updateStatus('status-extract', '⏳', 'Extracting files...');
                const extractResponse = await fetch('?step=download&action=extract');
                const extractData = await extractResponse.json();
                
                if (!extractData.success) {
                    throw new Error(extractData.error || 'Failed to extract files');
                }
                
                updateStatus('status-extract', '✓', 'Files extracted (' + extractData.files + ' files)');
                
                // Step 4: Prepare schema (only for new installations)
                <?php if (!$isUpdate): ?>
                updateStatus('status-schema', '⏳', 'Preparing database schema...');
                const schemaResponse = await fetch('?step=download&action=schema');
                const schemaData = await schemaResponse.json();
                
                if (!schemaData.success) {
                    throw new Error(schemaData.error || 'Failed to prepare schema');
                }
                
                updateStatus('status-schema', '✓', 'Database schema ready');
                <?php endif; ?>
                
                showSuccess();
                
            } catch (error) {
                console.error('Download error:', error);
                showError(error.message);
                
                // Mark current step as failed
                const steps = ['status-fetch', 'status-download', 'status-extract', 'status-schema'];
                for (const step of steps) {
                    const elem = document.getElementById(step);
                    if (elem && elem.textContent === '⏳') {
                        updateStatus(step, '✗', elem.nextElementSibling.textContent);
                        break;
                    }
                }
            }
        }
    </script>
    <?php
}

// Handle AJAX download actions
if ($step === 'download' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_GET['action'];
        $isUpdate = $_SESSION['update_mode'] ?? false;
        
        switch ($action) {
            case 'fetch':
                $releaseInfo = fetchLatestRelease();
                $_SESSION['release_info'] = $releaseInfo;
                echo json_encode(['success' => true, 'version' => $releaseInfo['tag_name']]);
                break;
                
            case 'download':
                $releaseInfo = $_SESSION['release_info'] ?? null;
                if (!$releaseInfo) {
                    throw new Exception('Release information not found');
                }
                $size = downloadRelease($releaseInfo);
                echo json_encode(['success' => true, 'size' => formatBytes($size)]);
                break;
                
            case 'extract':
                $files = extractRelease($isUpdate);
                echo json_encode(['success' => true, 'files' => $files]);
                break;
                
            case 'schema':
                if (!$isUpdate) {
                    prepareSchema();
                }
                echo json_encode(['success' => true]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function fetchLatestRelease(): array
{
    $ch = curl_init(GITHUB_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SlimStorage-Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Failed to fetch release: $error");
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("GitHub API returned HTTP $httpCode");
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['zipball_url'])) {
        throw new Exception("Invalid release data from GitHub");
    }
    
    return $data;
}

function downloadRelease(array $releaseInfo): int
{
    $zipUrl = $releaseInfo['zipball_url'];
    $zipFile = TEMP_DIR . '/release.zip';
    
    // Create temp directory
    if (!file_exists(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }
    
    // Download with progress
    $ch = curl_init($zipUrl);
    $fp = fopen($zipFile, 'w+');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SlimStorage-Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT, DOWNLOAD_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        throw new Exception("Failed to download release: $error");
    }
    
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to download release: HTTP $httpCode");
    }
    
    $_SESSION['zip_file'] = $zipFile;
    return filesize($zipFile);
}

function extractRelease(bool $isUpdate = false): int
{
    $zipFile = $_SESSION['zip_file'] ?? null;
    if (!$zipFile || !file_exists($zipFile)) {
        throw new Exception("Zip file not found");
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new Exception("Failed to open zip file");
    }
    
    // Extract to temp directory
    $extractPath = TEMP_DIR . '/extracted';
    if (!file_exists($extractPath)) {
        mkdir($extractPath, 0755, true);
    }
    
    $zip->extractTo($extractPath);
    $fileCount = $zip->numFiles;
    $zip->close();
    
    // Find the root directory (GitHub creates a subdirectory)
    $extracted = scandir($extractPath);
    $rootDir = null;
    foreach ($extracted as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
            $rootDir = $extractPath . '/' . $item;
            break;
        }
    }
    
    if (!$rootDir) {
        throw new Exception("Could not find extracted files");
    }
    
    // Backup .secrets.yml if updating
    $secretsBackup = null;
    if ($isUpdate && file_exists(SECRETS_FILE)) {
        $secretsBackup = file_get_contents(SECRETS_FILE);
    }
    
    // Copy files to installation directory
    copyDirectory($rootDir, INSTALL_DIR, $isUpdate);
    
    // Restore .secrets.yml if updating
    if ($secretsBackup) {
        file_put_contents(SECRETS_FILE, $secretsBackup);
    }
    
    // Cleanup
    deleteDirectory(TEMP_DIR);
    
    return $fileCount;
}

function prepareSchema(): void
{
    $schemaFile = INSTALL_DIR . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("schema.sql not found in the release");
    }
    
    $_SESSION['schema_ready'] = true;
}

function copyDirectory(string $source, string $dest, bool $skipSecrets = false): void
{
    if (!file_exists($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        // Skip .secrets.yml if updating
        if ($skipSecrets && $file === '.secrets.yml') {
            continue;
        }
        
        $srcPath = $source . '/' . $file;
        $destPath = $dest . '/' . $file;
        
        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $destPath, $skipSecrets);
        } else {
            copy($srcPath, $destPath);
        }
    }
    closedir($dir);
}

function deleteDirectory(string $dir): void
{
    if (!file_exists($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function loadExistingSecrets(): array
{
    if (!file_exists(SECRETS_FILE)) {
        return [];
    }
    
    $content = file_get_contents(SECRETS_FILE);
    $lines = explode("\n", $content);
    $config = [];
    $currentSection = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        
        // Check for section header
        if (!str_starts_with($line, ' ') && str_ends_with($line, ':')) {
            $currentSection = rtrim($line, ':');
            $config[$currentSection] = [];
            continue;
        }
        
        // Parse key-value pairs
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if ($currentSection !== null) {
                $config[$currentSection][$key] = $value;
            }
        }
    }
    
    return $config;
}

// ============================================================================
// STEP 4: Configuration Form
// ============================================================================

function renderConfigureStep(): void
{
    $isUpdate = $_SESSION['update_mode'] ?? false;
    
    // If updating and secrets exist, load them and skip to database step
    if ($isUpdate && file_exists(SECRETS_FILE)) {
        // Load existing configuration
        $existingConfig = loadExistingSecrets();
        $_SESSION['config'] = $existingConfig;
        $_SESSION['db_connection_ok'] = true;
        
        // Set up PDO session variables
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
            $existingConfig['database']['host'] ?? 'localhost',
            $existingConfig['database']['port'] ?? 3306,
            $existingConfig['database']['name'] ?? ''
        );
        $_SESSION['pdo_dsn'] = $dsn;
        $_SESSION['pdo_user'] = $existingConfig['database']['user'] ?? '';
        $_SESSION['pdo_password'] = $existingConfig['database']['password'] ?? '';
        
        header('Location: ?step=database');
        exit;
    }
    
    $currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $currentProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $currentUrl = $currentProtocol . '://' . $currentDomain;

    ?>
    <h1>Configuration</h1>
    <p class="subtitle">Enter your configuration details</p>

    <form method="POST" action="?step=process">
        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">📊 Database Settings</h3>
        
        <div class="grid-2">
            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Database Port</label>
                <input type="number" name="db_port" value="3306" required>
            </div>
        </div>

        <div class="form-group">
            <label>Database Name</label>
            <input type="text" name="db_name" required placeholder="my_database">
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>Database User</label>
                <input type="text" name="db_user" required placeholder="db_username">
            </div>
            <div class="form-group">
                <label>Database Password</label>
                <input type="password" name="db_password" required placeholder="••••••••">
            </div>
        </div>

        <div class="form-group">
            <label>Table Prefix</label>
            <input type="text" name="db_prefix" value="slimstore_" required>
            <small class="label-description">Prefix for all database tables (e.g., slimstore_)</small>
        </div>

        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">🌐 Domain Settings</h3>

        <div class="form-group">
            <label>Domain Name</label>
            <input type="text" name="domain_name" value="<?= htmlspecialchars($currentDomain) ?>" required>
            <small class="label-description">Your domain without protocol (e.g., services.example.com)</small>
        </div>

        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">🔑 Google OAuth Settings</h3>

        <div class="alert alert-info">
            <strong>Need Google OAuth credentials?</strong><br>
            1. Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color: #1e40af; text-decoration: underline;">Google Cloud Console</a><br>
            2. Create a new OAuth 2.0 Client ID<br>
            3. Add authorized redirect URI: <code><?= htmlspecialchars($currentUrl) ?>/admin/callback.php</code>
        </div>

        <div class="form-group">
            <label>Google Client ID</label>
            <input type="text" name="google_client_id" required placeholder="123456789-abcdefg.apps.googleusercontent.com">
        </div>

        <div class="form-group">
            <label>Google Client Secret</label>
            <input type="password" name="google_client_secret" required placeholder="GOCSPX-••••••••">
        </div>

        <div class="form-group">
            <label>OAuth Redirect URI</label>
            <input type="text" name="google_redirect_uri" value="<?= htmlspecialchars($currentUrl) ?>/admin/callback.php" required>
        </div>

        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">👤 Superadmin Settings</h3>

        <div class="form-group">
            <label>Superadmin Email</label>
            <input type="email" name="superadmin_email" required placeholder="admin@example.com">
            <small class="label-description">This email will have full admin access</small>
        </div>

        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">⚙️ API Settings (Optional)</h3>

        <div class="grid-2">
            <div class="form-group">
                <label>Rate Limit (requests)</label>
                <input type="number" name="rate_limit_requests" value="10000" required>
            </div>
            <div class="form-group">
                <label>Rate Limit Window (seconds)</label>
                <input type="number" name="rate_limit_window" value="60" required>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>Max Keys Per User</label>
                <input type="number" name="max_keys_per_user" value="100" required>
            </div>
            <div class="form-group">
                <label>Max Value Size (bytes)</label>
                <input type="number" name="max_value_size" value="262144" required>
            </div>
        </div>

        <div class="form-group">
            <label>Allowed CORS Origins</label>
            <input type="text" name="allowed_origins" value="<?= htmlspecialchars($currentUrl) ?>" placeholder="https://example.com,https://app.example.com">
            <small class="label-description">Comma-separated list of allowed origins for CORS</small>
        </div>

        <div class="btn-group">
            <a href="?step=requirements" class="btn btn-secondary">← Back</a>
            <button type="submit" class="btn">Continue →</button>
        </div>
    </form>
    <?php
}

// ============================================================================
// STEP 4: Process Configuration
// ============================================================================

function processConfiguration(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?step=configure');
        exit;
    }

    // Validate and sanitize inputs
    $config = [
        'database' => [
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'password' => $_POST['db_password'] ?? '',
            'prefix' => trim($_POST['db_prefix'] ?? 'slimstore_'),
        ],
        'domain' => [
            'name' => trim($_POST['domain_name'] ?? ''),
        ],
        'google_oauth' => [
            'client_id' => trim($_POST['google_client_id'] ?? ''),
            'client_secret' => trim($_POST['google_client_secret'] ?? ''),
            'redirect_uri' => trim($_POST['google_redirect_uri'] ?? ''),
        ],
        'superadmin' => [
            'email' => trim($_POST['superadmin_email'] ?? ''),
        ],
        'api' => [
            'rate_limit_requests' => (int)($_POST['rate_limit_requests'] ?? 10000),
            'rate_limit_window_seconds' => (int)($_POST['rate_limit_window'] ?? 60),
            'max_keys_per_user' => (int)($_POST['max_keys_per_user'] ?? 100),
            'max_value_size_bytes' => (int)($_POST['max_value_size'] ?? 262144),
            'allowed_origins' => trim($_POST['allowed_origins'] ?? ''),
        ],
    ];

    // Store in session for next step
    $_SESSION['config'] = $config;

    // Test database connection
    try {
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
            $config['database']['host'],
            $config['database']['port'],
            $config['database']['name']
        );

        $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $_SESSION['db_connection_ok'] = true;
        $_SESSION['pdo_dsn'] = $dsn;
        $_SESSION['pdo_user'] = $config['database']['user'];
        $_SESSION['pdo_password'] = $config['database']['password'];

        // Create .secrets.yml file
        createSecretsFile($config);

        header('Location: ?step=database');
        exit;

    } catch (PDOException $e) {
        ?>
        <h1>Database Connection Error</h1>
        <div class="alert alert-error">
            <strong>Failed to connect to database:</strong><br>
            <?= htmlspecialchars($e->getMessage()) ?>
        </div>
        <div class="btn-group">
            <a href="?step=configure" class="btn">← Back to Configuration</a>
        </div>
        <?php
        exit;
    }
}

function createSecretsFile(array $config): void
{
    $yaml = "# Secrets Configuration\n";
    $yaml .= "# WARNING: This file contains sensitive information.\n";
    $yaml .= "# Do NOT commit this file to version control.\n\n";

    $yaml .= "# Database Configuration\n";
    $yaml .= "database:\n";
    $yaml .= "  host: {$config['database']['host']}\n";
    $yaml .= "  port: {$config['database']['port']}\n";
    $yaml .= "  name: {$config['database']['name']}\n";
    $yaml .= "  user: {$config['database']['user']}\n";
    $yaml .= "  password: {$config['database']['password']}\n";
    $yaml .= "  prefix: {$config['database']['prefix']}\n\n";

    $yaml .= "# Domain Configuration\n";
    $yaml .= "domain:\n";
    $yaml .= "  name: {$config['domain']['name']}\n\n";

    $yaml .= "# Google OAuth Configuration\n";
    $yaml .= "google_oauth:\n";
    $yaml .= "  client_id: {$config['google_oauth']['client_id']}\n";
    $yaml .= "  client_secret: {$config['google_oauth']['client_secret']}\n";
    $yaml .= "  redirect_uri: {$config['google_oauth']['redirect_uri']}\n\n";

    $yaml .= "# Superadmin Configuration\n";
    $yaml .= "superadmin:\n";
    $yaml .= "  email: {$config['superadmin']['email']}\n\n";

    $yaml .= "# API Configuration\n";
    $yaml .= "api:\n";
    $yaml .= "  rate_limit_requests: {$config['api']['rate_limit_requests']}\n";
    $yaml .= "  rate_limit_window_seconds: {$config['api']['rate_limit_window_seconds']}\n";
    $yaml .= "  max_keys_per_user: {$config['api']['max_keys_per_user']}\n";
    $yaml .= "  max_value_size_bytes: {$config['api']['max_value_size_bytes']}\n";
    $yaml .= "  allowed_origins: {$config['api']['allowed_origins']}\n";

    if (file_put_contents(SECRETS_FILE, $yaml) === false) {
        throw new Exception("Failed to create .secrets.yml file");
    }

    // Set restrictive permissions
    chmod(SECRETS_FILE, 0600);
}

// ============================================================================
// STEP 5: Database Setup
// ============================================================================

function renderDatabaseStep(): void
{
    if (!isset($_SESSION['db_connection_ok'])) {
        header('Location: ?step=configure');
        exit;
    }

    $config = $_SESSION['config'];
    $prefix = $config['database']['prefix'];
    $isUpdate = $_SESSION['update_mode'] ?? false;

    ?>
    <h1>Database Setup</h1>
    <p class="subtitle"><?= $isUpdate ? 'Verifying database...' : 'Creating database tables...' ?></p>

    <?php
    try {
        $pdo = new PDO(
            $_SESSION['pdo_dsn'],
            $_SESSION['pdo_user'],
            $_SESSION['pdo_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if ($isUpdate) {
            // For updates, just verify the connection and skip schema creation
            ?>
            <div class="alert alert-success">
                ✓ Database connection verified! Your existing database will be preserved.
            </div>

            <div class="requirements">
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Database connection verified</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Existing tables preserved</span>
                </div>
            </div>
            <?php
        } else {
            // Get SQL schema file
            $schemaFile = INSTALL_DIR . '/schema.sql';
            
            if (!file_exists($schemaFile)) {
                throw new Exception("schema.sql file not found. Please ensure the download completed successfully.");
            }

            $sql = file_get_contents($schemaFile);
            
            // Replace table prefix placeholder
            $sql = str_replace('slimstore_', $prefix, $sql);

            // Execute SQL statements
            $pdo->exec($sql);

            ?>
            <div class="alert alert-success">
                ✓ Database tables created successfully!
            </div>

            <div class="requirements">
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>API Keys table</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Key/Value Store table</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Events table</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Sessions table</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>Rate Limits table</span>
                </div>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span>API Logs table</span>
                </div>
            </div>
            <?php
        }
        ?>

        <div class="btn-group">
            <a href="?step=complete" class="btn btn-success"><?= $isUpdate ? 'Complete Update →' : 'Complete Installation →' ?></a>
        </div>
        <?php

    } catch (Exception $e) {
        ?>
        <div class="alert alert-error">
            <strong>Database setup failed:</strong><br>
            <?= htmlspecialchars($e->getMessage()) ?>
        </div>
        <div class="btn-group">
            <a href="?step=configure" class="btn">← Back to Configuration</a>
        </div>
        <?php
    }
}

// ============================================================================
// STEP 6: Complete
// ============================================================================

function renderCompleteStep(): void
{
    $config = $_SESSION['config'] ?? [];
    $domain = $config['domain']['name'] ?? $_SERVER['HTTP_HOST'];
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $domain;
    $isUpdate = $_SESSION['update_mode'] ?? false;
    $releaseInfo = $_SESSION['release_info'] ?? null;
    $version = $releaseInfo['tag_name'] ?? 'latest';

    ?>
    <h1>🎉 <?= $isUpdate ? 'Update Complete!' : 'Installation Complete!' ?></h1>
    <p class="subtitle"><?= $isUpdate ? 'SlimStorage has been updated to ' . htmlspecialchars($version) : 'Your SlimStorage instance is ready to use' ?></p>

    <div class="alert alert-success">
        <strong>✓ <?= $isUpdate ? 'Update' : 'Installation' ?> successful!</strong><br>
        <?= $isUpdate ? 'All files have been updated to the latest version.' : 'All components have been configured and the database has been initialized.' ?>
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">🚀 Next Steps</h3>

    <div class="requirements">
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">1.</span>
            <span><strong>Delete this installer:</strong> Remove <code>install.php</code> for security</span>
        </div>
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">2.</span>
            <span><strong>Visit your site:</strong> <a href="<?= htmlspecialchars($baseUrl) ?>" target="_blank"><?= htmlspecialchars($baseUrl) ?></a></span>
        </div>
        <?php if (!$isUpdate): ?>
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">3.</span>
            <span><strong>Login with Google:</strong> Use the superadmin email you configured</span>
        </div>
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">4.</span>
            <span><strong>Create API keys:</strong> Generate keys from the dashboard</span>
        </div>
        <?php else: ?>
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">3.</span>
            <span><strong>Clear cache:</strong> Hard refresh your browser (Ctrl+Shift+R or Cmd+Shift+R)</span>
        </div>
        <div class="requirement">
            <span style="color: #667eea; font-weight: bold;">4.</span>
            <span><strong>Test your site:</strong> Verify all features are working correctly</span>
        </div>
        <?php endif; ?>
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">📚 Important URLs</h3>

    <div class="code-block">
Landing Page: <?= htmlspecialchars($baseUrl) ?>

Admin Dashboard: <?= htmlspecialchars($baseUrl) ?>/admin/

API Endpoint: <?= htmlspecialchars($baseUrl) ?>/api/

Event API: <?= htmlspecialchars($baseUrl) ?>/api/event/

Schema API: <?= htmlspecialchars($baseUrl) ?>/api/schema/
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: #667eea;">🔒 Security Reminders</h3>

    <div class="alert alert-warning">
        <strong>Important:</strong>
        <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
            <li>Delete <code>install.php</code> immediately</li>
            <li>Keep <code>.secrets.yml</code> secure and never commit it to version control</li>
            <li>Add <code>.secrets.yml</code> to your <code>.gitignore</code> file</li>
            <li>Use HTTPS in production (configure SSL certificate)</li>
            <li>Regularly backup your database</li>
        </ul>
    </div>

    <div class="btn-group">
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-success" target="_blank">Open SlimStorage →</a>
        <button onclick="if(confirm('Are you sure you want to delete the installer?')) { window.location.href='?step=delete'; }" class="btn btn-danger">Delete Installer</button>
    </div>

    <?php
    // Clear session
    session_destroy();
}

// ============================================================================
// Self-delete handler
// ============================================================================

if ($step === 'delete') {
    if (unlink(__FILE__)) {
        echo '<h1>✓ Installer Deleted</h1>';
        echo '<p>The installer has been successfully removed.</p>';
        $domain = $_SERVER['HTTP_HOST'];
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        echo '<a href="' . $protocol . '://' . $domain . '" class="btn">Go to SlimStorage →</a>';
    } else {
        echo '<h1>⚠️ Manual Deletion Required</h1>';
        echo '<p>Could not automatically delete installer. Please manually delete <code>install.php</code> from your server.</p>';
    }
    exit;
}

