<?php
$title       = 'Krok 2 — Mapowanie modeli';
$currentStep = 2;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Pre-build seasons map for labels
$seasonLabels = array_column($seasons ?? [], 'season', 'id');

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-1">Mapowanie modeli opon</h2>
        <p class="text-base-content/70 mb-6">
            Dla każdego modelu z pliku wybierz odpowiadający model z bazy danych lub oznacz jako nowy.
            Łącznie: <strong><?= count($models) ?></strong> unikalnych kombinacji producent + model.
        </p>

        <form method="POST"
              action="<?= htmlspecialchars($base . '/mapping', ENT_QUOTES, 'UTF-8') ?>">
            <?= \App\Csrf::field() ?>

            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Producent</th>
                            <th>Model z pliku</th>
                            <th class="w-16 text-center">Szt.</th>
                            <th>Akcja</th>
                            <th>Istniejący model (baza)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($models as $key => $model):
                            $encodedKey   = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                            $encodedProd  = htmlspecialchars($model['producer_name'], ENT_QUOTES, 'UTF-8');
                            $encodedModel = htmlspecialchars($model['model_name'], ENT_QUOTES, 'UTF-8');
                            $treads        = $treadsByProducer[$model['producer_name']] ?? [];
                            $modelNameLc   = strtolower($model['model_name']);
                            $rowId         = 'row_' . md5($key);
                            $hasMatch      = !empty($treads) && !empty(array_filter($treads, fn($t) => strtolower($t['tread']) === $modelNameLc));
                            $defaultAction = $hasMatch ? 'existing' : 'new';
                        ?>
                        <tr x-data="{ action: '<?= $defaultAction ?>' }">
                            <td class="font-medium"><?= $encodedProd ?></td>
                            <td><?= $encodedModel ?></td>
                            <td class="text-center">
                                <span class="badge badge-ghost"><?= (int) $model['count'] ?></span>
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <label class="label cursor-pointer gap-1">
                                        <input type="radio"
                                               name="action[<?= $encodedKey ?>]"
                                               value="existing"
                                               class="radio radio-primary radio-sm"
                                               x-model="action"
                                               <?= empty($treads) ? 'disabled' : ($defaultAction === 'existing' ? 'checked' : '') ?>>
                                        <span class="label-text <?= empty($treads) ? 'opacity-40' : '' ?>">Istniejący</span>
                                    </label>
                                    <label class="label cursor-pointer gap-1">
                                        <input type="radio"
                                               name="action[<?= $encodedKey ?>]"
                                               value="new"
                                               class="radio radio-warning radio-sm"
                                               x-model="action"
                                               <?= empty($treads) || $defaultAction === 'new' ? 'checked' : '' ?>>
                                        <span class="label-text text-warning font-medium">Nowy</span>
                                    </label>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($treads)):
                                    // Split into similar (share first 4 chars or substring match) and others
                                    $prefix4   = mb_strtolower(mb_substr($model['model_name'], 0, 4));
                                    $isSimilar = static function(string $name) use ($modelNameLc, $prefix4): bool {
                                        $lc = mb_strtolower($name);
                                        if ($prefix4 !== '' && str_starts_with($lc, $prefix4)) {
                                            return true;
                                        }
                                        if (mb_strlen($modelNameLc) >= 3 && str_contains($lc, $modelNameLc)) {
                                            return true;
                                        }
                                        return false;
                                    };
                                    $similar = array_filter($treads, fn($t) => $isSimilar($t['tread']));
                                    $others  = array_filter($treads, fn($t) => !$isSimilar($t['tread']));
                                ?>
                                <div x-show="action === 'existing'">
                                    <select name="existing_tread[<?= $encodedKey ?>]"
                                            class="select select-bordered select-sm w-full max-w-xs"
                                            x-bind:required="action === 'existing'">
                                        <option value="">— wybierz model —</option>
                                        <?php
                                        $renderOption = static function(array $tread, string $modelNameLc, array $seasonLabels): void {
                                            $name        = htmlspecialchars($tread['tread'], ENT_QUOTES, 'UTF-8');
                                            $sid         = (int) ($tread['season_id'] ?? 0);
                                            $seasonLabel = $sid && isset($seasonLabels[$sid])
                                                ? ' [' . htmlspecialchars($seasonLabels[$sid], ENT_QUOTES, 'UTF-8') . ']'
                                                : '';
                                            $selected    = strtolower($tread['tread']) === $modelNameLc ? ' selected' : '';
                                            echo '<option value="' . (int) $tread['id'] . '"' . $selected . '>'
                                                . $name . $seasonLabel . '</option>';
                                        };
                                        ?>
                                        <?php if (!empty($similar) && !empty($others)): ?>
                                            <optgroup label="Pasujące">
                                                <?php foreach ($similar as $tread): $renderOption($tread, $modelNameLc, $seasonLabels); endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Pozostałe (<?= count($others) ?>)">
                                                <?php foreach ($others as $tread): $renderOption($tread, $modelNameLc, $seasonLabels); endforeach; ?>
                                            </optgroup>
                                        <?php else: ?>
                                            <?php foreach ($treads as $tread): $renderOption($tread, $modelNameLc, $seasonLabels); endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div x-show="action === 'new'" x-cloak>
                                    <span class="badge badge-warning">+ nowy: <?= $encodedModel ?></span>
                                </div>
                                <?php else: ?>
                                <div>
                                    <span class="badge badge-warning">+ nowy: <?= $encodedModel ?></span>
                                    <span class="badge badge-error badge-sm ml-1">nowy producent</span>
                                    <label class="flex items-center gap-2 mt-2">
                                        <span class="text-xs text-base-content/60 whitespace-nowrap">Nazwa producenta:</span>
                                        <input type="text"
                                               name="new_producer_name[<?= $encodedKey ?>]"
                                               value="<?= $encodedProd ?>"
                                               class="input input-bordered input-xs flex-1 max-w-xs"
                                               placeholder="Nazwa producenta"
                                               required>
                                    </label>
                                    <span class="text-xs text-base-content/40 mt-1 block">zostanie dodany do bazy</span>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-actions justify-between mt-6">
                <a href="<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">
                    ← Wróć
                </a>
                <button type="submit" class="btn btn-primary btn-lg">
                    Dalej: sezony / import
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
</div>

<style>[x-cloak] { display: none !important; }</style>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
