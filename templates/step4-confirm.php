<?php
$title       = 'Krok 4 — Potwierdzenie importu';
$currentStep = 4;

$base      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$existing  = array_filter($mapping, fn($m) => !$m['is_new']);
$newModels = array_filter($mapping, fn($m) => $m['is_new']);

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-2">Potwierdzenie importu</h2>
        <p class="text-base-content/70 mb-6">
            Sprawdź podsumowanie przed uruchomieniem importu. Operacja jest nieodwracalna.
        </p>

        <div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-4">
            <div class="stat">
                <div class="stat-title">Mapowań łącznie</div>
                <div class="stat-value text-primary"><?= count($mapping) ?></div>
            </div>
            <div class="stat">
                <div class="stat-title">Istniejące modele</div>
                <div class="stat-value text-success"><?= count($existing) ?></div>
            </div>
            <div class="stat">
                <div class="stat-title">Nowe modele</div>
                <div class="stat-value text-warning"><?= count($newModels) ?></div>
            </div>
        </div>

        <?php if ($preview !== null): ?>
        <div class="stats stats-vertical lg:stats-horizontal shadow w-full mb-6">
            <div class="stat">
                <div class="stat-title">Nowe produkty</div>
                <div class="stat-value text-warning"><?= $preview['will_create'] ?></div>
                <div class="stat-desc">zostaną dodane</div>
            </div>
            <div class="stat">
                <div class="stat-title">Istniejące produkty</div>
                <div class="stat-value text-info"><?= $preview['will_update'] ?></div>
                <div class="stat-desc">cena i etykieta zaktualizowane</div>
            </div>
            <div class="stat">
                <div class="stat-title">Pominięte wiersze</div>
                <div class="stat-value text-base-content/40"><?= $preview['will_skip'] ?></div>
                <div class="stat-desc">brak ceny lub producent spoza mapowania</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($newModels)): ?>
        <div class="mb-6">
            <h3 class="font-semibold text-lg mb-2">Nowe modele do utworzenia</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm table-zebra">
                    <thead>
                        <tr><th>Producent</th><th>Model</th><th>Sezon</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($newModels as $m):
                            $sid = (int) $m['season_id'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($m['producer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="font-medium"><?= htmlspecialchars($m['model_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= $sid === 1 ? 'badge-warning' : ($sid === 2 ? 'badge-info' : 'badge-success') ?>">
                                    <?= htmlspecialchars($seasonMap[$sid] ?? "Sezon #{$sid}", ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="alert alert-warning mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <span>
                Import zaktualizuje istniejące opony zgodnie z opcjami poniżej. Nowe opony zostaną dodane do bazy danych.
                Tej operacji <strong>nie można cofnąć</strong>.
            </span>
        </div>

        <form method="POST"
              action="<?= htmlspecialchars($base . '/execute', ENT_QUOTES, 'UTF-8') ?>">
            <?= \App\Csrf::field() ?>

            <div class="card bg-base-200 mb-6">
                <div class="card-body py-4">
                    <h3 class="font-semibold text-base mb-3">Opcje aktualizacji istniejących opon</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="update_price" value="1" class="checkbox checkbox-primary" checked>
                            <span class="label-text">
                                <span class="font-medium">Cena</span>
                                <span class="block text-xs text-base-content/60">Nadpisuje cenę katalogową i cenniki</span>
                            </span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="update_labels" value="1" class="checkbox checkbox-primary" checked>
                            <span class="label-text">
                                <span class="font-medium">Etykieta EU</span>
                                <span class="block text-xs text-base-content/60">Opór toczenia, przyczepność, hałas, fale</span>
                            </span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="update_inne" value="1" class="checkbox checkbox-primary" checked>
                            <span class="label-text">
                                <span class="font-medium">Oznaczenia (inne)</span>
                                <span class="block text-xs text-base-content/60">Run-flat, wzmocnienie, homologacje, EPREL</span>
                            </span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="update_structure" value="1" class="checkbox">
                            <span class="label-text">
                                <span class="font-medium">Struktura rozmiaru</span>
                                <span class="block text-xs text-base-content/60">Wymiary, LI/SI, rozmiar — domyślnie wyłączone</span>
                            </span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="update_pricing" value="1" class="checkbox checkbox-primary">
                            <span class="label-text">
                                <span class="font-medium">Ceny katalogowe</span>
                                <span class="block text-xs text-base-content/60">Aktualizuje ceny katalogowe na podstawie REFów</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="card-actions justify-between">
                <a href="<?= htmlspecialchars($base . '/seasons', ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-ghost">← Wróć</a>

                <button type="submit" class="btn btn-error btn-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    Uruchom import
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
