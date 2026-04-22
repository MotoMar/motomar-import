<?php
$title       = 'Krok 3 — Przypisanie sezonów';
$currentStep = 3;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/**
 * Returns inline SVG + label for a given season ID.
 * IDs: 1 = Letnia, 2 = Zimowa, 3 = Całoroczna
 */
$svgFiles = [
    1 => __DIR__ . '/sunny.svg',
    2 => __DIR__ . '/snowy.svg',
    3 => __DIR__ . '/allseason.svg',
];

$seasonIcon = static function (int $id, string $name) use ($svgFiles): string {
    $raw = isset($svgFiles[$id]) ? (file_get_contents($svgFiles[$id]) ?: '') : '';

    // Strip XML declaration, DOCTYPE, and Illustrator comments
    $raw = preg_replace('/<\?xml[^?]*\?>/i', '', $raw);
    $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);
    $raw = preg_replace('/<!--.*?-->/s', '', $raw);
    // Remove id attributes to avoid duplicate-id conflicts
    $raw = preg_replace('/\s+id="[^"]*"/', '', $raw);
    // Drop fixed dimensions — let Tailwind control size
    $raw = preg_replace('/\s+width="[^"]*"/', '', $raw);
    $raw = preg_replace('/\s+height="[^"]*"/', '', $raw);
    $raw = preg_replace('/<svg\b/', '<svg class="w-10 h-10"', $raw);

    return sprintf(
        '<div class="w-full h-full flex flex-col items-center justify-center gap-0.5 p-1">%s<span class="text-[10px] font-semibold leading-tight text-base-content">%s</span></div>',
        trim($raw),
        htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
    );
};

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-1">Przypisanie sezonów i weryfikacja nazw</h2>
        <p class="text-base-content/70 mb-6">
            Nowe modele zostaną utworzone w bazie. Popraw nazwę jeśli trzeba i wybierz sezon.
        </p>

        <form method="POST"
              action="<?= htmlspecialchars($base . '/seasons', ENT_QUOTES, 'UTF-8') ?>">
            <?= \App\Csrf::field() ?>

            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Producent</th>
                            <th>Nazwa modelu <span class="font-normal text-base-content/50">(edytowalna)</span></th>
                            <th class="text-center">Sezon</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($newModels as $key => $model):
                            $encodedKey  = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                            $encodedProd = htmlspecialchars($model['producer_name'], ENT_QUOTES, 'UTF-8');
                            $encodedMod  = htmlspecialchars($model['model_name'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="font-medium align-middle"><?= $encodedProd ?></td>
                            <td class="align-middle">
                                <input type="text"
                                       name="model_name[<?= $encodedKey ?>]"
                                       value="<?= $encodedMod ?>"
                                       class="input input-bordered input-sm w-full max-w-xs"
                                       required
                                       maxlength="120">
                            </td>
                            <td class="align-middle">
                                <div class="flex gap-2 justify-center">
                                    <?php foreach ($seasons as $season):
                                        $sid         = (int) $season['id'];
                                        $seasonName  = htmlspecialchars($season['season'], ENT_QUOTES, 'UTF-8');
                                        $inputId     = 'season_' . md5($key) . '_' . $sid;
                                        $ringClasses = match ($sid) {
                                            1 => 'peer-checked:ring-orange-400 peer-checked:bg-orange-50',
                                            2 => 'peer-checked:ring-blue-400  peer-checked:bg-blue-50',
                                            3 => 'peer-checked:ring-teal-400  peer-checked:bg-teal-50',
                                            default => 'peer-checked:ring-neutral',
                                        };
                                    ?>
                                    <label for="<?= $inputId ?>" class="cursor-pointer" title="<?= $seasonName ?>">
                                        <input type="radio"
                                               id="<?= $inputId ?>"
                                               name="season[<?= $encodedKey ?>]"
                                               value="<?= $sid ?>"
                                               class="sr-only peer"
                                               required>
                                        <div class="w-16 h-16 rounded-xl border-2 border-base-300 ring-2 ring-transparent
                                                    transition-all <?= $ringClasses ?>
                                                    hover:border-base-400">
                                            <?= $seasonIcon($sid, $season['season']) ?>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-actions justify-between mt-6">
                <a href="<?= htmlspecialchars($base . '/mapping', ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-ghost">← Wróć do mapowania</a>

                <button type="submit" class="btn btn-primary btn-lg">
                    Dalej: potwierdzenie
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
