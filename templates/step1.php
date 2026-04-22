<?php
$title       = 'Krok 1 — Wgranie pliku cennika';
$currentStep = 1;

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-2">Wgranie pliku cennika</h2>
        <p class="text-base-content/70 mb-3">
            Plik CSV z kolumnami oddzielonymi znakiem <code class="badge badge-ghost badge-sm">@</code>.
            Nagłówek jest opcjonalny — zostanie automatycznie pominięty.
        </p>

        <div class="collapse collapse-arrow border border-base-300 rounded-xl mb-6">
            <input type="checkbox">
            <div class="collapse-title text-sm font-medium text-base-content/70">
                Format pliku — 18 kolumn
            </div>
            <div class="collapse-content">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5 pt-1">
                    <?php
                    $columns = [
                        1  => ['numkat1',  'Nr katalogowy 1'],
                        2  => ['numkat2',  'Nr katalogowy 2'],
                        3  => ['ean',      'Kod EAN'],
                        4  => ['id',       'ID zewnętrzne'],
                        5  => ['producent','Producent'],
                        6  => ['rodzaj',   'Rodzaj pojazdu'],
                        7  => ['bieznik',  'Bieżnik / model'],
                        8  => ['rozmiar',  'Rozmiar'],
                        9  => ['rozmiar2', 'Rozmiar 2'],
                        10 => ['indeksy',  'Indeksy LI/SI'],
                        11 => ['indeksy2', 'Indeksy 2'],
                        12 => ['inne',     'Dodatkowe'],
                        13 => ['opor',     'Opór toczenia'],
                        14 => ['mokre',    'Przyczepność'],
                        15 => ['halas',    'Hałas (dB)'],
                        16 => ['fale',     'Klasa hałasu'],
                        17 => ['eprel',    'ID EPREL'],
                        18 => ['netto',    'Cena netto'],
                    ];
                    foreach ($columns as $n => [$code, $label]):
                    ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="badge badge-ghost badge-sm tabular-nums w-6 shrink-0"><?= $n ?></span>
                        <code class="text-primary shrink-0"><?= $code ?></code>
                        <span class="text-base-content/50 truncate"><?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error mb-4">
            <span><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST"
              action="<?= htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/upload', ENT_QUOTES, 'UTF-8') ?>"
              enctype="multipart/form-data"
              x-data="{ fileName: '', dragging: false }"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="dragging = false; fileName = $event.dataTransfer.files[0]?.name ?? ''">

            <?= \App\Csrf::field() ?>

            <div class="form-control mb-6">
                <label
                    :class="dragging ? 'border-primary bg-primary/10' : 'border-base-300'"
                    class="flex flex-col items-center justify-center w-full h-40 border-2 border-dashed rounded-xl cursor-pointer transition-colors hover:border-primary hover:bg-primary/5">

                    <div class="flex flex-col items-center gap-2 text-base-content/60" x-show="!fileName">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <span class="font-medium">Przeciągnij plik lub kliknij aby wybrać</span>
                        <span class="text-sm">CSV, maks. 10 MB</span>
                    </div>

                    <div class="flex items-center gap-2 text-success" x-show="fileName" x-cloak>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="font-medium" x-text="fileName"></span>
                    </div>

                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain"
                           class="hidden"
                           @change="fileName = $event.target.files[0]?.name ?? ''" required>
                </label>
            </div>

            <div class="card-actions justify-end">
                <button type="submit" class="btn btn-primary btn-lg" :disabled="!fileName">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                    Dalej: mapowanie modeli
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
