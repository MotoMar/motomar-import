<?php
// Syntax checker
header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP SYNTAX CHECK ===\n\n";

$files = [
    '../src/Controller/UploadController.php',
    '../src/Controller/MappingController.php',
    '../src/Domain/Import/ImportSession.php',
    '../templates/step2.php',
    '../templates/step1.php',
    '../templates/layout.php',
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    echo "Checking: $file\n";

    if (!file_exists($path)) {
        echo "  [ERROR] File not found\n\n";
        continue;
    }

    exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $code);

    if ($code === 0) {
        echo "  [OK] No syntax errors\n";
    } else {
        echo "  [ERROR] Syntax error detected:\n";
        foreach ($output as $line) {
            echo "    $line\n";
        }
    }

    echo "\n";
    $output = [];
}

echo "=== Checking for recent errors in Apache log ===\n";
$apacheLog = '/var/log/apache2/error.log';
if (file_exists($apacheLog)) {
    $lines = file($apacheLog);
    $recent = array_slice($lines, -50);

    echo "Last 50 lines (filtering for PHP errors):\n\n";
    foreach ($recent as $line) {
        if (stripos($line, 'php') !== false || stripos($line, 'fatal') !== false || stripos($line, 'parse') !== false) {
            echo $line;
        }
    }
} else {
    echo "(Apache log not found)\n";
}

echo "\n=== END SYNTAX CHECK ===\n";
