<?php
$title       = 'Krok 2a — Nowi producenci';
$currentStep = 2;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="card bg-base-100 shadow">
    <div class="card-body">
        <h2 class="card-title text-2xl mb-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            Wykryto nowych producentów
        </h2>
        <p class="text-base-content/70 mb-6">
            Producenci poniżej nie istnieją w bazie danych. Możesz poprawić ich nazwy
            (np. zmienić wielkość liter, dodać pełną nazwę) oraz wybrać klasę producenta.
            Wykryto <strong><?= count($newProducers) ?></strong>
            <?= count($newProducers) === 1 ? 'nowego producenta' : 'nowych producentów' ?>.
        </p>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span><?= htmlspecialchars($_SESSION['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars($base . '/producers', ENT_QUOTES, 'UTF-8') ?>">
            <?= \App\Csrf::field() ?>

            <div class="space-y-4 mb-6">
                <?php foreach ($newProducers as $producer): ?>
                <div class="card bg-base-200 border border-base-300">
                    <div class="card-body p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-bold text-lg">
                                <?= htmlspecialchars($producer['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </h3>
                            <div class="badge badge-primary">
                                <?= $producer['count'] ?> <?= $producer['count'] === 1 ? 'produkt' : 'produktów' ?>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">Nazwa producenta w sklepie</span>
                                </label>
                                <input
                                    type="text"
                                    name="producer_name_<?= base64_encode($producer['name']) ?>"
                                    value="<?= htmlspecialchars(ucwords(strtolower($producer['name'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                    placeholder="np. Nexen Tire, Sailun, Torque"
                                    class="input input-bordered w-full"
                                    required
                                    autocomplete="off"
                                />
                                <label class="label">
                                    <span class="label-text-alt text-base-content/60">
                                        Nazwa z cennika: <strong><?= htmlspecialchars($producer['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                    </span>
                                </label>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">Klasa producenta</span>
                                </label>
                                <select
                                    name="producer_class_<?= base64_encode($producer['name']) ?>"
                                    class="select select-bordered w-full"
                                    required
                                >
                                    <?php foreach ($classifications as $classification): ?>
                                    <option
                                        value="<?= $classification['id'] ?>"
                                        <?= $classification['id'] === 2 ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($classification['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="label">
                                    <span class="label-text-alt text-base-content/60">
                                        Domyślnie: <strong>Średnia</strong>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="card-actions justify-between">
                <button
                    type="button"
                    class="btn btn-ghost"
                    onclick="if(confirm('Czy na pewno chcesz anulować? Trzeba będzie ponownie wgrać plik CSV.')) { window.location.href='<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>'; }"
                >
                    ← Anuluj
                </button>
                <button type="submit" class="btn btn-primary btn-lg">
                    Zapisz i kontynuuj mapowanie
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
