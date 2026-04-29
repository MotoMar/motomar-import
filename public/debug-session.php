<?php
// Debug script for session data - remove after troubleshooting
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Bootstrap;
use App\Domain\Import\ImportSession;

Bootstrap::init();

header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION DEBUG ===\n\n";

$session = new ImportSession(dirname(__DIR__) . '/storage');
$uuid = $session->uuid();
$step = $session->step();

echo "UUID: " . ($uuid ?? '(none)') . "\n";
echo "Step: $step\n\n";

if ($uuid !== null) {
    $importDir = dirname(__DIR__) . '/storage/imports/' . $uuid;

    echo "Import directory: $importDir\n";
    echo "Directory exists: " . (is_dir($importDir) ? 'yes' : 'no') . "\n\n";

    if (is_dir($importDir)) {
        $files = array_diff(scandir($importDir), ['.', '..']);
        echo "Files in directory:\n";
        foreach ($files as $file) {
            $path = $importDir . '/' . $file;
            $size = filesize($path);
            echo "  - $file ($size bytes)\n";
        }
        echo "\n";

        // Read models
        echo "--- models.json ---\n";
        $models = $session->read($uuid, 'models');
        if ($models === null) {
            echo "(file not found or null)\n";
        } else {
            echo "Count: " . count($models) . "\n";
            if (count($models) > 0) {
                echo "First 3 models:\n";
                $i = 0;
                foreach ($models as $key => $model) {
                    if ($i >= 3) break;
                    echo "  Key: $key\n";
                    echo "    Producer: " . ($model['producer_name'] ?? '?') . "\n";
                    echo "    Model: " . ($model['model_name'] ?? '?') . "\n";
                    echo "    Count: " . ($model['count'] ?? '?') . "\n";
                    $i++;
                }
            }
        }
        echo "\n";

        // Read mapping
        echo "--- mapping.json ---\n";
        $mapping = $session->read($uuid, 'mapping');
        if ($mapping === null) {
            echo "(file not found or null)\n";
        } else {
            echo "Count: " . count($mapping) . "\n";
            if (count($mapping) > 0) {
                echo "First 3 mappings:\n";
                $i = 0;
                foreach ($mapping as $key => $map) {
                    if ($i >= 3) break;
                    echo "  Key: $key\n";
                    echo "    Producer: " . ($map['producer_name'] ?? '?') . "\n";
                    echo "    Model: " . ($map['model_name'] ?? '?') . "\n";
                    echo "    Tread ID: " . ($map['tread_id'] ?? '?') . "\n";
                    echo "    Is new: " . ($map['is_new'] ? 'yes' : 'no') . "\n";
                    $i++;
                }
            }
        }
        echo "\n";

        // Check CSV file
        echo "--- CSV file ---\n";
        $csvPath = $importDir . '/original.csv';
        if (file_exists($csvPath)) {
            $lines = file($csvPath);
            echo "Lines: " . count($lines) . "\n";
            echo "First 3 lines:\n";
            for ($i = 0; $i < min(3, count($lines)); $i++) {
                echo "  " . ($i + 1) . ": " . substr(trim($lines[$i]), 0, 100) . "...\n";
            }
        } else {
            echo "(CSV file not found)\n";
        }
    }
} else {
    echo "No active import session\n";
}

echo "\n--- PHP error_log ---\n";
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo "From: $errorLog\n\n";
    $lines = file($errorLog);
    $recent = array_slice($lines, -30);
    echo implode('', $recent);
} else {
    echo "(error log not found or not configured)\n";
    echo "Checking common locations:\n";
    $commonLogs = [
        '/var/log/php_errors.log',
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
    ];
    foreach ($commonLogs as $log) {
        if (file_exists($log)) {
            echo "\nFound: $log\n";
            $lines = file($log);
            $recent = array_slice($lines, -10);
            echo implode('', $recent);
        }
    }
}

echo "\n--- Application Logs ---\n";
$logsDir = dirname(__DIR__) . '/storage/logs';
$logFiles = glob($logsDir . '/*.log');
if (!empty($logFiles)) {
    usort($logFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latest = $logFiles[0];
    echo "From: " . basename($latest) . "\n\n";
    $content = file_get_contents($latest);
    $lines = explode("\n", $content);
    $recent = array_slice($lines, -30);
    echo implode("\n", $recent);
} else {
    echo "(no log files found)\n";
}

echo "\n\n=== END SESSION DEBUG ===\n";
