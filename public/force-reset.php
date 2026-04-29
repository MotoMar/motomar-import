<?php
// Force reset - emergency cleanup script
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Bootstrap;

Bootstrap::init();

header('Content-Type: text/plain; charset=utf-8');

echo "=== FORCE RESET ===\n\n";

// Clear session variables
$keys = ['_csrf_token', 'ti_uuid', 'ti_step'];
foreach ($keys as $key) {
    if (isset($_SESSION[$key])) {
        echo "Unsetting session key: $key (value: " . $_SESSION[$key] . ")\n";
        unset($_SESSION[$key]);
    }
}

// Regenerate session
session_regenerate_id(true);
echo "Session regenerated\n";
echo "New session ID: " . session_id() . "\n\n";

// Clean up old import directories (older than 1 hour)
$importsDir = dirname(__DIR__) . '/storage/imports';
$cutoff = time() - 3600; // 1 hour ago

echo "Cleaning up old import sessions...\n";

if (is_dir($importsDir)) {
    $dirs = array_diff(scandir($importsDir), ['.', '..', '.gitkeep']);

    foreach ($dirs as $dir) {
        $path = $importsDir . '/' . $dir;

        if (!is_dir($path)) {
            continue;
        }

        $mtime = filemtime($path);
        $age = time() - $mtime;

        echo "  - $dir (age: " . round($age / 60) . " minutes)";

        if ($mtime < $cutoff) {
            echo " [DELETING]";
            try {
                // Simple recursive delete
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $file) {
                    if ($file->isDir()) {
                        rmdir($file->getRealPath());
                    } else {
                        unlink($file->getRealPath());
                    }
                }

                rmdir($path);
                echo " [OK]\n";
            } catch (\Throwable $e) {
                echo " [FAILED: " . $e->getMessage() . "]\n";
            }
        } else {
            echo " [KEEPING]\n";
        }
    }
}

echo "\n=== RESET COMPLETE ===\n";
echo "You can now return to the upload page:\n";
echo "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/\n";
