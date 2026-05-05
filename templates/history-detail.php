<?php
$title       = 'Szczegóły importu';
$currentStep = null;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-6">Szczegóły importu</h2>

        <!-- Import Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-bold">Data importu</span>
                </label>
                <p class="font-mono"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($import['imported_at']))) ?></p>
            </div>
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-bold">Producent</span>
                </label>
                <p class="font-mono"><code><?= htmlspecialchars($import['producer']) ?></code></p>
            </div>
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-bold">Użytkownik</span>
                </label>
                <p><?= htmlspecialchars($import['imported_by']) ?></p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="divider">Wyniki operacji</div>
        <div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-6">
            <div class="stat">
                <div class="stat-title">Dodane</div>
                <div class="stat-value text-success"><?= htmlspecialchars((string) $import['created_count']) ?></div>
                <div class="stat-desc">nowe opony</div>
            </div>
            <div class="stat">
                <div class="stat-title">Zaktualizowane</div>
                <div class="stat-value text-info"><?= htmlspecialchars((string) $import['updated_count']) ?></div>
                <div class="stat-desc">zmienione dane</div>
            </div>
            <div class="stat">
                <div class="stat-title">Pominięte</div>
                <div class="stat-value text-neutral"><?= htmlspecialchars((string) $import['skipped_count']) ?></div>
                <div class="stat-desc">wierszy pominięto</div>
            </div>
            <div class="stat">
                <div class="stat-title">Błędy</div>
                <div class="stat-value <?= $import['error_count'] > 0 ? 'text-error' : 'text-neutral' ?>">
                    <?= htmlspecialchars((string) $import['error_count']) ?>
                </div>
                <div class="stat-desc">wierszy z błędem</div>
            </div>
        </div>

        <!-- Options -->
        <div class="divider">Opcje importu</div>
        <div class="overflow-x-auto mb-6">
            <table class="table table-compact w-full">
                <tbody>
                    <?php foreach ($import['options'] as $key => $value): ?>
                    <tr>
                        <td class="font-mono"><strong><?= htmlspecialchars($key) ?></strong></td>
                        <td><?= $value ? '<span class="badge badge-success">TAK</span>' : '<span class="badge badge-neutral">NIE</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Errors (if any) -->
        <?php if (!empty($import['error_messages'])): ?>
        <div class="divider text-error">Błędy</div>
        <div class="alert alert-error mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l-2-2m0 0l-2-2m2 2l2-2m-2 2l-2 2m10-10l-2 2m0 0l-2-2m2 2l2-2m-2 2l-2 2M9 3h6a2 2 0 012 2v12a2 2 0 01-2 2H9a2 2 0 01-2-2V5a2 2 0 012-2z" />
            </svg>
            <span>Podczas importu wystąpiło <?= htmlspecialchars((string) count($import['error_messages'])) ?> błędów.</span>
        </div>

        <div class="bg-base-200 rounded-lg p-4 max-h-96 overflow-y-auto mb-6">
            <ul class="list-disc list-inside space-y-2 text-sm font-mono">
                <?php foreach ($import['error_messages'] as $err): ?>
                <li class="text-error"><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <div class="alert alert-success mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Import zakończony pomyślnie bez błędów.</span>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="card-actions justify-between">
            <a href="<?= htmlspecialchars($base . '/history', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-ghost">
                ← Wróć do historii
            </a>
            <a href="<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-primary">
                Nowy import
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
?>

