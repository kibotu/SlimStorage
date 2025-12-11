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
const GITHUB_REPO = 'kibotu/slimstorage'; // Change this to your actual GitHub repo
const GITHUB_API_URL = 'https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest';
const INSTALL_DIR = __DIR__;
// .secrets.yml goes ONE FOLDER UP from web root (where config.php expects it: __DIR__ . '/../.secrets.yml')
const SECRETS_FILE = INSTALL_DIR . '/../.secrets.yml';
// schema.sql also goes one folder up (outside web root for security)
const SCHEMA_FILE = INSTALL_DIR . '/../schema.sql';
const PUBLIC_DIR = INSTALL_DIR . '/public';
const TEMP_DIR = INSTALL_DIR . '/temp_install';
const DOWNLOAD_TIMEOUT = 300; // 5 minutes

// Detect update mode
$isUpdateMode = isset($_GET['update']) && $_GET['update'] === '1';

// Start session early (needed for installation flow tracking)
session_start();

// Check if we're in the middle of an active installation (config was just saved)
$isActiveInstallation = isset($_SESSION['db_connection_ok']) || isset($_SESSION['config']);
$currentStep = $_GET['step'] ?? 'welcome';

// Security: Prevent access after installation is complete (unless updating or in active installation)
// Allow database and complete steps if we're in an active installation session
$allowedStepsWithSecrets = ['database', 'complete', 'delete'];
$isAllowedStep = in_array($currentStep, $allowedStepsWithSecrets) && $isActiveInstallation;

if (file_exists(SECRETS_FILE) && !isset($_GET['force']) && !$isUpdateMode && !$isAllowedStep) {
    die('⚠️ Installation already complete. Delete .secrets.yml or add ?force=1 to reinstall, or ?update=1 to update.');
}

// Store update mode in session
if ($isUpdateMode) {
    $_SESSION['update_mode'] = true;
}

// Handle different installation steps (use $currentStep from earlier)
$step = $currentStep;

// Early handler for database step - redirect to configure if not ready
if ($step === 'database' && !isset($_SESSION['db_connection_ok'])) {
    header('Location: ?step=configure');
    exit;
}

// Early handler for configure step - handle update mode redirect before HTML output
if ($step === 'configure') {
    $isUpdate = $_SESSION['update_mode'] ?? false;
    
    // If updating and secrets exist, skip configuration and go to database step
    if ($isUpdate && file_exists(SECRETS_FILE)) {
        // Load existing configuration
        $existingConfig = loadExistingSecretsEarly();
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
}

// Early handler for process step - must run BEFORE any HTML output (for redirects)
if ($step === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handleProcessConfiguration();
    if ($result['redirect']) {
        header('Location: ' . $result['redirect']);
        exit;
    }
    // If there's an error, store it in session and let the HTML page render it
    $_SESSION['process_error'] = $result['error'] ?? null;
}

// Early AJAX handler - must run BEFORE any HTML output
if ($step === 'download' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_GET['action'];
        $isUpdate = $_SESSION['update_mode'] ?? false;
        
        switch ($action) {
            case 'fetch':
                $releaseInfo = ajaxFetchLatestRelease();
                $_SESSION['release_info'] = $releaseInfo;
                echo json_encode(['success' => true, 'version' => $releaseInfo['tag_name']]);
                break;
                
            case 'download':
                $releaseInfo = $_SESSION['release_info'] ?? null;
                if (!$releaseInfo) {
                    throw new Exception('Release information not found');
                }
                $size = ajaxDownloadRelease($releaseInfo);
                echo json_encode(['success' => true, 'size' => ajaxFormatBytes($size)]);
                break;
                
            case 'extract':
                $files = ajaxExtractRelease($isUpdate);
                echo json_encode(['success' => true, 'files' => $files]);
                break;
                
            case 'schema':
                if (!$isUpdate) {
                    ajaxPrepareSchema();
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

// Helper functions for early AJAX handler (defined before HTML output)
function ajaxFetchLatestRelease(): array
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

function ajaxDownloadRelease(array $releaseInfo): int
{
    // Find the slimstore-{version}.zip asset from the release (curated, smaller)
    // Fall back to zipball_url if no release asset found (full repo)
    $zipUrl = $releaseInfo['zipball_url'];
    $isReleaseAsset = false;

    if (!empty($releaseInfo['assets'])) {
        foreach ($releaseInfo['assets'] as $asset) {
            // Match slimstore-*.zip but not the checksum file
            if (preg_match('/^slimstore-.*\.zip$/', $asset['name']) && !str_ends_with($asset['name'], '.sha256')) {
                $zipUrl = $asset['browser_download_url'];
                $isReleaseAsset = true;
                break;
            }
        }
    }

    $_SESSION['is_release_asset'] = $isReleaseAsset;
    $_SESSION['download_url'] = $zipUrl;
    $zipFile = TEMP_DIR . '/release.zip';

    // Create temp directory
    if (!file_exists(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }

    // Download with retry logic (GitHub sometimes returns 503)
    $maxRetries = 3;
    $retryDelay = 2; // seconds
    $lastError = '';
    $lastHttpCode = 0;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($zipUrl);
        $fp = fopen($zipFile, 'w+');

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SlimStorage-Installer/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, DOWNLOAD_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $result = curl_exec($ch);
        $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($result === false) {
            $lastError = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            
            if ($attempt < $maxRetries) {
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
                continue;
            }
            throw new Exception("Failed to download release after $maxRetries attempts: $lastError");
        }

        curl_close($ch);
        fclose($fp);

        // Success
        if ($lastHttpCode === 200) {
            break;
        }
        
        // Retry on 5xx errors
        if ($lastHttpCode >= 500 && $attempt < $maxRetries) {
            sleep($retryDelay);
            $retryDelay *= 2;
            continue;
        }
        
        throw new Exception("Failed to download release: HTTP $lastHttpCode (attempt $attempt/$maxRetries). URL: $zipUrl");
    }
    
    $_SESSION['zip_file'] = $zipFile;
    return filesize($zipFile);
}

function ajaxExtractRelease(bool $isUpdate = false): int
{
    $extractLog = [];
    $zipFile = $_SESSION['zip_file'] ?? TEMP_DIR . '/release.zip';
    $isReleaseAsset = $_SESSION['is_release_asset'] ?? false;
    
    $extractLog[] = "Starting extraction...";
    $extractLog[] = "Zip file: $zipFile";
    $extractLog[] = "Is release asset: " . ($isReleaseAsset ? 'yes' : 'no');
    $extractLog[] = "INSTALL_DIR: " . INSTALL_DIR;
    $extractLog[] = "SCHEMA_FILE target: " . SCHEMA_FILE;
    $extractLog[] = "SECRETS_FILE target: " . SECRETS_FILE;
    
    // Check if parent directory is writable
    $parentDir = dirname(SCHEMA_FILE);
    $parentWritable = is_writable($parentDir);
    $extractLog[] = "Parent dir: $parentDir";
    $extractLog[] = "Parent dir writable: " . ($parentWritable ? 'yes' : 'NO! This may cause problems');

    if (!file_exists($zipFile)) {
        throw new Exception('Release zip file not found');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new Exception('Failed to open zip file');
    }
    
    $extractLog[] = "Zip opened successfully, contains " . $zip->numFiles . " files";

    // Find the root folder in the zip (GitHub zipball adds a folder like "repo-version")
    // Release assets created by our workflow have no root folder
    $rootFolder = '';
    if (!$isReleaseAsset) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '/') !== false) {
                $rootFolder = substr($name, 0, strpos($name, '/') + 1);
                break;
            }
        }
    }
    
    $fileCount = 0;
    $filesToSkip = ['install.php', '.secrets.yml', '.secrets-sample.yml', '.gitignore'];
    
    // Files/folders that should NOT be extracted to web root (except schema.sql which we need)
    $nonWebFiles = ['scripts/', 'README.md', '.gitignore', '.secrets-sample.yml'];
    
    // If updating, also skip certain config files
    if ($isUpdate) {
        $filesToSkip[] = '.secrets.yml';
    }
    
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        
        // Remove root folder prefix
        $relativePath = $rootFolder ? substr($name, strlen($rootFolder)) : $name;
        
        // Skip empty paths, root folder, and specific files
        if (empty($relativePath) || $relativePath === '/') {
            continue;
        }
        
        // Skip files we don't want to overwrite
        $skipThis = false;
        foreach ($filesToSkip as $skipFile) {
            if ($relativePath === $skipFile || str_ends_with($relativePath, '/' . $skipFile)) {
                $skipThis = true;
                break;
            }
        }
        if ($skipThis) {
            continue;
        }
        
        // Skip non-web files (scripts/, README.md, etc.) - but keep schema.sql
        foreach ($nonWebFiles as $nonWebFile) {
            if ($relativePath === $nonWebFile || str_starts_with($relativePath, $nonWebFile)) {
                $skipThis = true;
                break;
            }
        }
        if ($skipThis) {
            continue;
        }
        
        // Handle public/ folder specially - extract its contents to INSTALL_DIR (flatten it)
        if (str_starts_with($relativePath, 'public/')) {
            $relativePath = substr($relativePath, 7); // Remove 'public/' prefix
            if (empty($relativePath)) {
                continue; // Skip the public/ folder itself
            }
            $targetPath = INSTALL_DIR . '/' . $relativePath;
        } elseif ($relativePath === 'schema.sql') {
            // schema.sql goes to parent directory (outside web root)
            $targetPath = SCHEMA_FILE;
        } else {
            // Skip any other root-level files/folders
            continue;
        }
        
        // Create directory if needed
        if (str_ends_with($name, '/')) {
            if (!file_exists($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            // Extract file
            $dir = dirname($targetPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                $result = file_put_contents($targetPath, $content);
                if ($result === false) {
                    $extractLog[] = "FAILED to write: $targetPath";
                } else {
                    $fileCount++;
                    // Log important files
                    if (str_contains($targetPath, 'schema.sql') || str_contains($targetPath, 'index.php') || str_contains($targetPath, 'config.php')) {
                        $extractLog[] = "Extracted: $targetPath ($result bytes)";
                    }
                }
            }
        }
    }

    $zip->close();
    
    $extractLog[] = "Extraction complete. Files extracted: $fileCount";
    $extractLog[] = "schema.sql exists after extraction: " . (file_exists(SCHEMA_FILE) ? 'YES at ' . SCHEMA_FILE : 'NO!');
    
    // Store extraction log in session for debugging
    $_SESSION['extract_log'] = $extractLog;

    // Clean up zip file
    unlink($zipFile);
    if (is_dir(TEMP_DIR)) {
        @rmdir(TEMP_DIR);
    }
    
    return $fileCount;
}

function ajaxPrepareSchema(): void
{
    // Read the schema file from the extracted files
    if (!file_exists(SCHEMA_FILE)) {
        throw new Exception('Schema file not found after extraction at: ' . SCHEMA_FILE);
    }

    $_SESSION['schema_sql'] = file_get_contents(SCHEMA_FILE);
}

function ajaxFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Load existing secrets from .secrets.yml (early version for pre-HTML handlers)
 */
function loadExistingSecretsEarly(): array
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

/**
 * Handle configuration processing before HTML output (for redirects)
 */
function handleProcessConfiguration(): array
{
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
        createSecretsFileEarly($config);

        return ['redirect' => '?step=database', 'error' => null];

    } catch (PDOException $e) {
        return ['redirect' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Create secrets file (early version for pre-HTML handler)
 */
function createSecretsFileEarly(array $config): void
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SlimStorage Installer</title>
    <style>
        /* Inter font import for consistent typography */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        :root {
            /* Color Palette - Dark Mode matching main app */
            --bg-body: #0f172a;
            --bg-body-gradient: radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.15) 0px, transparent 50%), 
                                radial-gradient(at 100% 0%, rgba(236, 72, 153, 0.15) 0px, transparent 50%), 
                                radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.15) 0px, transparent 50%), 
                                radial-gradient(at 0% 100%, rgba(232, 121, 249, 0.15) 0px, transparent 50%);
            
            --bg-card: rgba(30, 41, 59, 0.7);
            --bg-card-hover: rgba(30, 41, 59, 0.85);
            --bg-input: rgba(15, 23, 42, 0.6);
            
            --border-color: rgba(148, 163, 184, 0.1);
            --border-color-hover: rgba(148, 163, 184, 0.2);
            
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --primary-glow: rgba(59, 130, 246, 0.5);
            
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.2);
            
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.2);
            
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --danger-bg: rgba(239, 68, 68, 0.2);
            
            --radius-sm: 6px;
            --radius-md: 12px;
            
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --font-mono: 'Monaco', 'Courier New', monospace;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-body);
            background-image: var(--bg-body-gradient);
            background-attachment: fixed;
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            backdrop-filter: blur(8px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
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
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
            font-size: 2.5rem;
            font-weight: 800;
            text-align: center;
            letter-spacing: -0.05em;
        }

        h3 {
            color: var(--text-primary);
            font-weight: 600;
        }

        .subtitle {
            text-align: center;
            color: var(--text-secondary);
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
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .step.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.2);
            box-shadow: 0 0 20px var(--primary-glow);
        }

        .step.completed {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
        }

        .label-description {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: normal;
            margin-top: 0.25rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            background: rgba(15, 23, 42, 0.8);
        }

        input::placeholder {
            color: var(--text-muted);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: white;
            padding: 0.875rem 2rem;
            border: 1px solid transparent;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.15);
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 0 25px rgba(37, 99, 235, 0.4);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            border-color: var(--border-color);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--border-color-hover);
            box-shadow: none;
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #059669;
            box-shadow: 0 0 25px rgba(16, 185, 129, 0.4);
        }

        .btn-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.3);
            box-shadow: none;
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
            border-color: var(--danger);
            color: #fecaca;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: var(--success-bg);
            border-color: rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        .alert-error {
            background: var(--danger-bg);
            border-color: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-warning {
            background: var(--warning-bg);
            border-color: rgba(245, 158, 11, 0.3);
            color: #fcd34d;
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
            color: #93c5fd;
        }

        .alert a {
            color: #60a5fa;
            text-decoration: underline;
        }

        .requirements {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            margin: 1.5rem 0;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
        }

        .requirement-ok {
            color: var(--success);
        }

        .requirement-fail {
            color: var(--danger);
        }

        .code-block {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            font-family: var(--font-mono);
            font-size: 0.875rem;
            overflow-x: auto;
            margin: 1rem 0;
            border: 1px solid var(--border-color);
            white-space: pre-wrap;
            word-break: break-all;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #38bdf8, #818cf8);
            transition: width 0.3s;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
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
            color: var(--text-secondary);
        }

        .feature-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--success);
            font-weight: bold;
            font-size: 1.2rem;
        }

        .collapsible {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border-color);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin: 1rem 0;
            cursor: pointer;
            user-select: none;
            color: var(--text-secondary);
        }

        .collapsible:hover {
            background: rgba(15, 23, 42, 0.7);
            border-color: var(--border-color-hover);
        }

        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .collapsible-content.active {
            max-height: 1000px;
        }
        
        code {
            font-family: var(--font-mono);
            background: rgba(0, 0, 0, 0.3);
            padding: 0.125rem 0.375rem;
            border-radius: 4px;
            font-size: 0.85em;
            color: #a5b4fc;
        }
        
        small {
            color: var(--text-muted);
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
    
    <div id="version-info" class="alert alert-info" style="display: none;">
        <strong>Version:</strong> <span id="version-number">checking...</span>
    </div>

    <div id="download-status" class="requirements">
        <div class="requirement">
            <span id="status-fetch">⏳</span>
            <span id="status-fetch-text">Fetching release information...</span>
        </div>
        <div class="requirement">
            <span id="status-download">⏳</span>
            <span id="status-download-text">Downloading release package...</span>
        </div>
        <div class="requirement">
            <span id="status-extract">⏳</span>
            <span id="status-extract-text">Extracting files...</span>
        </div>
        <?php if (!$isUpdate): ?>
        <div class="requirement">
            <span id="status-schema">⏳</span>
            <span id="status-schema-text">Preparing database schema...</span>
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

                // Show version info
                document.getElementById('version-info').style.display = 'block';
                document.getElementById('version-number').textContent = data.version || 'unknown';
                
                updateStatus('status-fetch', '✓', 'Release ' + (data.version || '') + ' found');

                // Step 2: Download (with retry info)
                updateStatus('status-download', '⏳', 'Downloading release package (may retry on errors)...');
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
    
    // Find the root directory (GitHub creates a subdirectory for zipball)
    // For release assets, files are at the root
    $extracted = scandir($extractPath);
    $rootDir = $extractPath;
    foreach ($extracted as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
            // Check if this looks like a GitHub zipball root (repo-version pattern)
            if (preg_match('/^[a-zA-Z0-9_-]+-[a-f0-9]+$/', $item) || preg_match('/^slimstorage-/', $item)) {
                $rootDir = $extractPath . '/' . $item;
                break;
            }
        }
    }
    
    // Backup .secrets.yml if updating
    $secretsBackup = null;
    if ($isUpdate && file_exists(SECRETS_FILE)) {
        $secretsBackup = file_get_contents(SECRETS_FILE);
    }
    
    // Check if there's a public/ folder - if so, extract its contents to INSTALL_DIR
    $publicDir = $rootDir . '/public';
    if (is_dir($publicDir)) {
        // Copy contents of public/ to INSTALL_DIR (flatten it)
        copyDirectory($publicDir, INSTALL_DIR, $isUpdate);
        
        // Also copy schema.sql if it exists (needed for database setup)
        $schemaFile = $rootDir . '/schema.sql';
        if (file_exists($schemaFile)) {
            copy($schemaFile, SCHEMA_FILE);
        }
    } else {
        // Fallback: copy everything (old behavior for non-standard releases)
        copyDirectory($rootDir, INSTALL_DIR, $isUpdate);
    }
    
    // Restore .secrets.yml if updating
    if ($secretsBackup) {
        file_put_contents(SECRETS_FILE, $secretsBackup);
    }
    
    // Cleanup temp directory
    deleteDirectory(TEMP_DIR);
    
    return $fileCount;
}

function prepareSchema(): void
{
    if (!file_exists(SCHEMA_FILE)) {
        throw new Exception("schema.sql not found at: " . SCHEMA_FILE);
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

// ============================================================================
// STEP 4: Configuration Form
// ============================================================================

function renderConfigureStep(): void
{
    // Note: Update mode redirect is now handled in the early handler (before HTML output)
    
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
            <small class="label-description">
                Domains that can make API calls from browser JavaScript. Your own domain is pre-filled. 
                Add other domains (comma-separated) only if you'll call the API from other websites.
                <strong>Note:</strong> Google OAuth doesn't need CORS - it uses server-side redirects.
            </small>
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
    // If not a POST request, redirect to configure step
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ?>
        <h1>Configuration Required</h1>
        <div class="alert alert-warning">
            <strong>No configuration data received.</strong><br>
            Please complete the configuration form first.
        </div>
        <div class="btn-group">
            <a href="?step=configure" class="btn">← Go to Configuration</a>
        </div>
        <?php
        return;
    }

    // Check if there was an error from the pre-HTML handler
    $error = $_SESSION['process_error'] ?? null;
    unset($_SESSION['process_error']);
    
    if ($error) {
        ?>
        <h1>Database Connection Error</h1>
        <div class="alert alert-error">
            <strong>Failed to connect to database:</strong><br>
            <?= htmlspecialchars($error) ?>
        </div>
        <div class="btn-group">
            <a href="?step=configure" class="btn">← Back to Configuration</a>
        </div>
        <?php
        return;
    }
    
    // If we got here without an error and without redirect, something went wrong
    ?>
    <h1>Processing Configuration</h1>
    <div class="alert alert-info">
        <strong>Processing your configuration...</strong><br>
        If you're not redirected automatically, <a href="?step=database">click here to continue</a>.
    </div>
    <script>
        // Fallback redirect
        setTimeout(function() {
            window.location.href = '?step=database';
        }, 2000);
    </script>
    <?php
}

// ============================================================================
// STEP 5: Database Setup
// ============================================================================

function renderDatabaseStep(): void
{
    // Note: Redirect to configure step is now handled in the early handler (before HTML output)

    $config = $_SESSION['config'] ?? null;
    $debugLog = [];
    $debugLog[] = "Starting database setup...";
    $debugLog[] = "Config exists: " . ($config ? 'yes' : 'no');
    
    if (!$config) {
        ?>
        <h1>Database Setup Error</h1>
        <div class="alert alert-error">
            <strong>Configuration not found in session.</strong><br>
            Please start the installation from the beginning.
        </div>
        <div class="btn-group">
            <a href="?step=configure" class="btn">← Back to Configuration</a>
        </div>
        <?php
        return;
    }
    
    $prefix = $config['database']['prefix'] ?? 'slimstore_';
    $isUpdate = $_SESSION['update_mode'] ?? false;
    $debugLog[] = "Table prefix: " . $prefix;
    $debugLog[] = "Update mode: " . ($isUpdate ? 'yes' : 'no');
    $debugLog[] = "PDO DSN: " . ($_SESSION['pdo_dsn'] ?? 'NOT SET');
    $debugLog[] = "INSTALL_DIR: " . INSTALL_DIR;
    $debugLog[] = "SECRETS_FILE: " . SECRETS_FILE;
    $debugLog[] = "SECRETS_FILE exists: " . (file_exists(SECRETS_FILE) ? 'yes' : 'NO!');
    $debugLog[] = "SCHEMA_FILE: " . SCHEMA_FILE;
    $debugLog[] = "Parent dir writable: " . (is_writable(dirname(SCHEMA_FILE)) ? 'yes' : 'NO!');
    
    // Include extraction log if available
    if (!empty($_SESSION['extract_log'])) {
        $debugLog[] = "--- EXTRACTION LOG ---";
        foreach ($_SESSION['extract_log'] as $log) {
            $debugLog[] = "  " . $log;
        }
        $debugLog[] = "--- END EXTRACTION LOG ---";
    }

    ?>
    <h1>Database Setup</h1>
    <p class="subtitle"><?= $isUpdate ? 'Verifying database...' : 'Creating database tables...' ?></p>

    <?php
    try {
        $debugLog[] = "Attempting database connection...";
        
        $pdo = new PDO(
            $_SESSION['pdo_dsn'],
            $_SESSION['pdo_user'],
            $_SESSION['pdo_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $debugLog[] = "Database connection successful!";

        // Check if tables already exist
        $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $tablesExist = count($existingTables) > 0;
        $debugLog[] = "Existing tables check: " . ($tablesExist ? count($existingTables) . " tables found" : "NO tables found");
        
        if ($isUpdate && $tablesExist) {
            // For updates with existing tables, just verify the connection and skip schema creation
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
                    <span>Existing tables preserved (<?= count($existingTables) ?> tables)</span>
                </div>
            </div>
            <?php
        } else {
            // Fresh install OR update mode with no existing tables - need to run schema
            if ($isUpdate && !$tablesExist) {
                $debugLog[] = "Update mode but no tables exist - will create schema";
            }
            // Get SQL schema file (located one folder up from web root)
            $debugLog[] = "Looking for schema file at: " . SCHEMA_FILE;
            $debugLog[] = "Schema file exists: " . (file_exists(SCHEMA_FILE) ? 'yes' : 'NO!');

            if (!file_exists(SCHEMA_FILE)) {
                // List files in parent dir for debugging
                $parentDir = dirname(SCHEMA_FILE);
                $files = is_dir($parentDir) ? scandir($parentDir) : ['(directory not accessible)'];
                $debugLog[] = "Files in parent directory: " . implode(', ', $files);
                $debugLog[] = "Files in INSTALL_DIR: " . implode(', ', scandir(INSTALL_DIR));
                throw new Exception("schema.sql file not found at: " . SCHEMA_FILE . ". Check debug log for file listings.");
            }

            $sql = file_get_contents(SCHEMA_FILE);
            $debugLog[] = "Schema file size: " . strlen($sql) . " bytes";

            // Replace table prefix placeholder
            $sql = str_replace('slimstore_', $prefix, $sql);
            $debugLog[] = "Applied table prefix: " . $prefix;

            // Execute SQL statements one by one (PDO::exec doesn't reliably handle multiple statements)
            // Split by semicolons at end of lines
            $statements = preg_split('/;\s*$/m', $sql);
            $debugLog[] = "Total statements found: " . count($statements);
            
            $executedCount = 0;
            $errors = [];

            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                // Skip empty statements and pure comment lines
                if (empty($statement)) {
                    continue;
                }
                // Skip if it's only comments
                $withoutComments = preg_replace('/--.*$/m', '', $statement);
                $withoutComments = trim($withoutComments);
                if (empty($withoutComments)) {
                    continue;
                }
                
                try {
                    $result = $pdo->exec($statement);
                    $executedCount++;
                    // Extract table name if it's a CREATE TABLE statement
                    if (preg_match('/CREATE TABLE.*?`([^`]+)`/i', $statement, $matches)) {
                        $debugLog[] = "✓ Created table: " . $matches[1];
                    }
                } catch (PDOException $stmtError) {
                    $errors[] = "Statement #$index failed: " . $stmtError->getMessage();
                    $debugLog[] = "✗ Statement #$index failed: " . $stmtError->getMessage();
                    // Continue with other statements
                }
            }
            
            $debugLog[] = "Executed $executedCount statements successfully";
            
            // Verify tables were created
            $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $debugLog[] = "Tables in database: " . (count($tables) > 0 ? implode(', ', $tables) : 'NONE!');

            if (count($tables) === 0) {
                throw new Exception("No tables were created! Executed $executedCount statements. Check the debug log below.");
            }
            
            if (!empty($errors)) {
                ?>
                <div class="alert alert-warning">
                    ⚠️ Some statements had errors (tables may still have been created):
                    <ul style="margin-top: 0.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php
            }

            ?>
            <?php if ($isUpdate): ?>
            <div class="alert alert-info">
                ℹ️ Update mode detected, but no existing tables were found. Schema has been created.
            </div>
            <?php endif; ?>
            <div class="alert alert-success">
                ✓ Database tables created successfully! (<?= $executedCount ?> statements, <?= count($tables) ?> tables)
            </div>

            <div class="requirements">
                <?php foreach ($tables as $table): ?>
                <div class="requirement">
                    <span class="requirement-ok">✓</span>
                    <span><?= htmlspecialchars($table) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php
        }
        ?>

        <!-- Debug Log (collapsible) -->
        <div class="collapsible" onclick="this.nextElementSibling.classList.toggle('active')">
            📋 Debug Log (click to expand)
        </div>
        <div class="collapsible-content">
            <div class="code-block">
<?php foreach ($debugLog as $log): ?>
<?= htmlspecialchars($log) ?>
<?php endforeach; ?>
            </div>
        </div>

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
        
        <!-- Debug Log for errors -->
        <div class="collapsible" onclick="this.nextElementSibling.classList.toggle('active')">
            📋 Debug Log (click to expand)
        </div>
        <div class="collapsible-content">
            <div class="code-block">
<?php foreach ($debugLog as $log): ?>
<?= htmlspecialchars($log) ?>
<?php endforeach; ?>
            </div>
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

    // Clean up schema.sql (it's already outside web root, but clean up anyway)
    if (file_exists(SCHEMA_FILE)) {
        @unlink(SCHEMA_FILE);
    }

    // Save version to VERSION file for display in footer
    $versionFile = INSTALL_DIR . '/VERSION';
    @file_put_contents($versionFile, $version);

    ?>
    <h1>🎉 <?= $isUpdate ? 'Update Complete!' : 'Installation Complete!' ?></h1>
    <p class="subtitle"><?= $isUpdate ? 'SlimStorage has been updated to ' . htmlspecialchars($version) : 'Your SlimStorage instance is ready to use' ?></p>

    <div class="alert alert-success">
        <strong>✓ <?= $isUpdate ? 'Update' : 'Installation' ?> successful!</strong><br>
        <?= $isUpdate ? 'All files have been updated to the latest version.' : 'All components have been configured and the database has been initialized.' ?>
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem;">🚀 Next Steps</h3>

    <div class="requirements">
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">1.</span>
            <span><strong>Delete this installer:</strong> Remove <code>install.php</code> for security</span>
        </div>
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">2.</span>
            <span><strong>Visit your site:</strong> <a href="<?= htmlspecialchars($baseUrl) ?>"><?= htmlspecialchars($baseUrl) ?></a></span>
        </div>
        <?php if (!$isUpdate): ?>
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">3.</span>
            <span><strong>Login with Google:</strong> Use the superadmin email you configured</span>
        </div>
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">4.</span>
            <span><strong>Create API keys:</strong> Generate keys from the dashboard</span>
        </div>
        <?php else: ?>
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">3.</span>
            <span><strong>Clear cache:</strong> Hard refresh your browser (Ctrl+Shift+R or Cmd+Shift+R)</span>
        </div>
        <div class="requirement">
            <span style="color: var(--primary); font-weight: bold;">4.</span>
            <span><strong>Test your site:</strong> Verify all features are working correctly</span>
        </div>
        <?php endif; ?>
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem;">📚 Important URLs</h3>

    <div class="code-block">
Landing Page: <?= htmlspecialchars($baseUrl) ?>

Admin Dashboard: <?= htmlspecialchars($baseUrl) ?>/admin/

API Endpoint: <?= htmlspecialchars($baseUrl) ?>/api/

Event API: <?= htmlspecialchars($baseUrl) ?>/api/event/

Schema API: <?= htmlspecialchars($baseUrl) ?>/api/schema/
    </div>

    <h3 style="margin-top: 2rem; margin-bottom: 1rem;">🔒 Security Reminders</h3>

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
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-success">Open SlimStorage Now →</a>
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

