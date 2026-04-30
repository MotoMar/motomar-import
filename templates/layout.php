<!DOCTYPE html>
<html lang="pl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Import cenników opon', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4/dist/full.min.css" rel="stylesheet" type="text/css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
</head>
<body class="bg-base-200 min-h-screen">

<?php $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
<div class="navbar bg-base-100 shadow-sm px-4">
    <div class="navbar-start">
        <span class="text-xl font-bold text-primary">Import cenników opon</span>
    </div>
    <div class="navbar-center gap-4 hidden md:flex">
        <?php if (\App\Auth::check()): ?>
            <a href="<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-ghost btn-sm">
                Import
            </a>
            <a href="<?= htmlspecialchars($base . '/history', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-ghost btn-sm">
                Historia
            </a>
        <?php endif; ?>
    </div>
    <div class="navbar-end gap-2">
        <?php if (\App\Auth::check()): ?>
            <span class="text-sm text-base-content/60 hidden sm:inline">
                <?= htmlspecialchars(\App\Auth::email() ?? '', ENT_QUOTES, 'UTF-8') ?>
            </span>
            <a href="<?= htmlspecialchars($base . '/logout', ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-ghost btn-sm">
                Wyloguj
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="container mx-auto max-w-5xl px-4 py-8">

    <?php if (($currentStep ?? 0) > 0): ?>
    <!-- Stepper -->
    <ul class="steps w-full mb-8">
        <li class="step <?= $currentStep >= 1 ? 'step-primary' : '' ?>">Wgranie pliku</li>
        <li class="step <?= $currentStep >= 2 ? 'step-primary' : '' ?>">Mapowanie modeli</li>
        <li class="step <?= $currentStep >= 3 ? 'step-primary' : '' ?>">Przypisanie sezonów</li>
        <li class="step <?= $currentStep >= 4 ? 'step-primary' : '' ?>">Potwierdzenie</li>
        <li class="step <?= $currentStep >= 5 ? 'step-primary' : '' ?>">Wyniki</li>
    </ul>
    <?php endif; ?>

    <?php if (!empty($_SESSION['_flash_error'])): ?>
        <div class="alert alert-error mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span><?= htmlspecialchars($_SESSION['_flash_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <?php unset($_SESSION['_flash_error']); ?>
    <?php endif; ?>

    <?= $content ?? '' ?>

</div>
</body>
</html>
