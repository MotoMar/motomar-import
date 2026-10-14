<?php

declare(strict_types=1);

/**
 * Pobiera i rozpakowuje dzienny eksport opon z EPREL (wszystkie modele, ~70 MB
 * ZIP-a, kilkaset tysięcy rejestracji). Czyta go `tools/enis/match_export.py`.
 *
 * Wymaga `EPREL_API_KEY` (zmienna środowiskowa albo `.env`); limit z kluczem to
 * 5 żądań na sekundę, więc jedno pobranie dziennie mieści się z zapasem.
 *
 *   php bin/eprelExport.php [KATALOG]     domyślnie tmp/eprel-export/RRRR-MM-DD
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Eprel\ApiKey;
use App\Eprel\RegistrationFetcher;

$root = dirname(__DIR__);
$directory = is_string($argv[1] ?? null) ? $argv[1] : $root . '/tmp/eprel-export/' . date('Y-m-d');

if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
    throw new RuntimeException("Nie mogę utworzyć katalogu: {$directory}");
}

$zip = $directory . '/tyres.zip';
$file = fopen($zip, 'w') ?: throw new RuntimeException("Nie mogę pisać do: {$zip}");
$handle = curl_init(RegistrationFetcher::BASE_URL . '/api/exportProducts/tyres');
curl_setopt_array($handle, [
    CURLOPT_FILE => $file,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_USERAGENT => RegistrationFetcher::USER_AGENT,
    CURLOPT_HTTPHEADER => ['x-api-key: ' . ApiKey::get($root . '/.env')],
]);
$ok = curl_exec($handle);
$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$error = curl_error($handle);
fclose($file);

if (true !== $ok || 200 !== $status) {
    unlink($zip);
    fwrite(STDERR, "Eksport nie pobrany: HTTP {$status} {$error}\n");
    exit(1);
}

$archive = new ZipArchive();

if (true !== $archive->open($zip)) {
    fwrite(STDERR, "To nie jest ZIP: {$zip}\n");
    exit(1);
}

$archive->extractTo($directory);
$archive->close();
unlink($zip);

foreach (glob($directory . '/*.json') ?: [] as $path) {
    printf("  %s  %d MB\n", basename($path), (int) round((filesize($path) ?: 0) / 1048576));
}

echo $directory, "\n";
