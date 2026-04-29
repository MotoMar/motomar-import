<?php
// Debug script - remove after troubleshooting
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== MOTOMAR IMPORT DEBUG INFO ===\n\n";

echo "PHP Version: " . PHP_VERSION . "\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n\n";

echo "--- PHP Configuration ---\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "error_log: " . (ini_get('error_log') ?: '(not set - using default)') . "\n";
echo "log_errors: " . (ini_get('log_errors') ? 'yes' : 'no') . "\n";
echo "display_errors: " . (ini_get('display_errors') ? 'yes' : 'no') . "\n\n";

echo "--- Session Configuration ---\n";
session_start();
echo "Session save path: " . session_save_path() . "\n";
echo "Session ID: " . session_id() . "\n";
echo "Session name: " . session_name() . "\n\n";

echo "--- Directory Permissions ---\n";
$root = dirname(__DIR__);
$storageDir = $root . '/storage';
$importsDir = $storageDir . '/imports';
$logsDir = $storageDir . '/logs';

echo "Root: $root\n";
echo "  exists: " . (is_dir($root) ? 'yes' : 'no') . "\n\n";

echo "Storage: $storageDir\n";
echo "  exists: " . (is_dir($storageDir) ? 'yes' : 'no') . "\n";
echo "  readable: " . (is_readable($storageDir) ? 'yes' : 'no') . "\n";
echo "  writable: " . (is_writable($storageDir) ? 'yes' : 'no') . "\n\n";

echo "Imports: $importsDir\n";
echo "  exists: " . (is_dir($importsDir) ? 'yes' : 'no') . "\n";
echo "  readable: " . (is_readable($importsDir) ? 'yes' : 'no') . "\n";
echo "  writable: " . (is_writable($importsDir) ? 'yes' : 'no') . "\n";
if (is_dir($importsDir)) {
    $imports = array_diff(scandir($importsDir), ['.', '..', '.gitkeep']);
    echo "  import sessions: " . count($imports) . "\n";
    if (!empty($imports)) {
        foreach ($imports as $dir) {
            echo "    - $dir\n";
        }
    }
}
echo "\n";

echo "Logs: $logsDir\n";
echo "  exists: " . (is_dir($logsDir) ? 'yes' : 'no') . "\n";
echo "  readable: " . (is_readable($logsDir) ? 'yes' : 'no') . "\n";
echo "  writable: " . (is_writable($logsDir) ? 'yes' : 'no') . "\n";
if (is_dir($logsDir)) {
    $logs = array_diff(scandir($logsDir), ['.', '..']);
    echo "  log files: " . count($logs) . "\n";
    if (!empty($logs)) {
        foreach ($logs as $file) {
            $path = $logsDir . '/' . $file;
            echo "    - $file (" . filesize($path) . " bytes)\n";
        }
    }
}
echo "\n";

echo "--- Session Data ---\n";
echo "Session keys: " . implode(', ', array_keys($_SESSION)) . "\n";
if (!empty($_SESSION)) {
    foreach ($_SESSION as $key => $value) {
        if (is_scalar($value)) {
            echo "  $key: " . var_export($value, true) . "\n";
        } else {
            echo "  $key: " . gettype($value) . "\n";
        }
    }
}
echo "\n";

try {
    echo "--- Database Connection ---\n";
    \App\Bootstrap::init();
    echo "Bootstrap initialized: yes\n";

    $db = \App\Bootstrap::db();
    echo "Database connected: yes\n";

    // Test query
    $result = $db->select('products_producers', 'COUNT(*) as count');
    echo "Producers count: " . ($result[0]['count'] ?? 'N/A') . "\n";

    $result = $db->select('tires_seasons', 'COUNT(*) as count');
    echo "Seasons count: " . ($result[0]['count'] ?? 'N/A') . "\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n--- Recent Logs (last 20 lines) ---\n";
if (is_dir($logsDir)) {
    $files = glob($logsDir . '/*.log');
    if (!empty($files)) {
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $files[0];
        echo "From: " . basename($latest) . "\n\n";
        $lines = file($latest);
        $recent = array_slice($lines, -20);
        echo implode('', $recent);
    } else {
        echo "(no log files found)\n";
    }
} else {
    echo "(logs directory does not exist)\n";
}

echo "\n=== END DEBUG INFO ===\n";
