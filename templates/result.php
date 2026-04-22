<?php
$title       = 'Wyniki importu';
$currentStep = 5;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-6">Wyniki importu</h2>

        <div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-6">
            <div class="stat">
                <div class="stat-title">Dodane</div>
                <div class="stat-value text-success"><?= (int) ($stats['created'] ?? 0) ?></div>
                <div class="stat-desc">nowe opony w bazie</div>
            </div>
            <div class="stat">
                <div class="stat-title">Zaktualizowane</div>
                <div class="stat-value text-primary"><?= (int) ($stats['updated'] ?? 0) ?></div>
                <div class="stat-desc">cena / etykiety</div>
            </div>
            <div class="stat">
                <div class="stat-title">Pominięte</div>
                <div class="stat-value text-neutral"><?= (int) ($stats['skipped'] ?? 0) ?></div>
                <div class="stat-desc">brak ceny / producenta</div>
            </div>
            <div class="stat">
                <div class="stat-title">Błędy</div>
                <div class="stat-value <?= empty($stats['errors']) ? 'text-neutral' : 'text-error' ?>">
                    <?= count($stats['errors'] ?? []) ?>
                </div>
                <div class="stat-desc">wierszy z błędem</div>
            </div>
        </div>

        <?php if (!empty($stats['errors'])): ?>
        <div class="collapse collapse-arrow bg-base-200 mb-6">
            <input type="checkbox">
            <div class="collapse-title font-medium text-error">
                Szczegóły błędów (<?= count($stats['errors']) ?>
                <?= !empty($stats['errors_capped']) ? ' — lista skrócona, więcej w logach' : '' ?>)
            </div>
            <div class="collapse-content">
                <ul class="list-disc list-inside space-y-1 text-sm font-mono">
                    <?php foreach ($stats['errors'] as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($stats['errors'])): ?>
        <div class="alert alert-success mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Import zakończony pomyślnie bez błędów.</span>
        </div>
        <?php endif; ?>

        <div class="card-actions justify-end">
            <a href="<?= htmlspecialchars($base . '/reset', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-primary">
                Nowy import
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
