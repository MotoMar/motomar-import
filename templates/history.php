<?php
$title       = 'Historia importów';
$currentStep = null;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-6">Historia importów</h2>

        <!-- Statistics Summary -->
        <div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-6">
            <div class="stat">
                <div class="stat-title">Łącznie importów</div>
                <div class="stat-value text-primary"><?= htmlspecialchars((string) $stats['total_imports']) ?></div>
                <div class="stat-desc">wykonanych operacji</div>
            </div>
            <div class="stat">
                <div class="stat-title">Dodane</div>
                <div class="stat-value text-success"><?= htmlspecialchars((string) $stats['total_created']) ?></div>
                <div class="stat-desc">nowe opony</div>
            </div>
            <div class="stat">
                <div class="stat-title">Zaktualizowane</div>
                <div class="stat-value text-info"><?= htmlspecialchars((string) $stats['total_updated']) ?></div>
                <div class="stat-desc">zmienione dane</div>
            </div>
            <div class="stat">
                <div class="stat-title">Błędy</div>
                <div class="stat-value <?= $stats['total_errors'] > 0 ? 'text-error' : 'text-neutral' ?>">
                    <?= htmlspecialchars((string) $stats['total_errors']) ?>
                </div>
                <div class="stat-desc">wierszy z błेdem</div>
            </div>
        </div>

        <!-- Imports List -->
        <?php if (empty($imports)): ?>
        <div class="alert alert-info">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="h-6 w-6 shrink-0 stroke-current">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span>Brak historii importów.</span>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto mb-6">
            <table class="table table-zebra w-full">
                <thead>
                    <tr>
                        <th>Data importu</th>
                        <th>Plik</th>
                        <th>Dodane</th>
                        <th>Zaktualizowane</th>
                        <th>Pominięte</th>
                        <th>Błędy</th>
                        <th>Użytkownik</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $import): ?>
                    <tr>
                        <td class="font-mono text-sm">
                            <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($import['imported_at']))) ?>
                        </td>
                        <td class="text-sm">
                            <code><?= htmlspecialchars($import['filename']) ?></code>
                        </td>
                        <td class="text-success font-bold">
                            <?= htmlspecialchars((string) $import['created_count']) ?>
                        </td>
                        <td class="text-info">
                            <?= htmlspecialchars((string) $import['updated_count']) ?>
                        </td>
                        <td class="text-neutral">
                            <?= htmlspecialchars((string) $import['skipped_count']) ?>
                        </td>
                        <td class="<?= $import['error_count'] > 0 ? 'text-error font-bold' : 'text-neutral' ?>">
                            <?= htmlspecialchars((string) $import['error_count']) ?>
                        </td>
                        <td class="text-sm">
                            <?= htmlspecialchars($import['imported_by']) ?>
                        </td>
                        <td>
                            <a href="<?= htmlspecialchars($base . '/history-detail?id=' . $import['id'], ENT_QUOTES, 'UTF-8') ?>"
                               class="btn btn-sm btn-ghost">
                                Szczegóły
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="join mx-auto mb-6">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === $page): ?>
                    <button class="join-item btn btn-active">
                        <?= htmlspecialchars((string) $i) ?>
                    </button>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($base . '/history?page=' . $i, ENT_QUOTES, 'UTF-8') ?>"
                       class="join-item btn">
                        <?= htmlspecialchars((string) $i) ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Actions -->
        <div class="card-actions justify-between">
            <a href="<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-ghost">
                ← Wróć
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

