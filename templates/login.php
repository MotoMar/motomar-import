<?php
$title       = 'Logowanie';
$currentStep = 0;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="flex justify-center">
    <div class="card bg-base-100 shadow w-full max-w-sm">
        <div class="card-body">
            <h2 class="card-title text-2xl mb-4">Logowanie</h2>

            <form method="POST"
                  action="<?= htmlspecialchars($base . '/login', ENT_QUOTES, 'UTF-8') ?>">
                <?= \App\Csrf::field() ?>

                <div class="form-control mb-4">
                    <label class="label" for="email">
                        <span class="label-text">E-mail</span>
                    </label>
                    <input type="email"
                           id="email"
                           name="email"
                           class="input input-bordered"
                           required
                           autofocus
                           autocomplete="email">
                </div>

                <div class="form-control mb-6">
                    <label class="label" for="password">
                        <span class="label-text">Hasło</span>
                    </label>
                    <input type="password"
                           id="password"
                           name="password"
                           class="input input-bordered"
                           required
                           autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Zaloguj się
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
