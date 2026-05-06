<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Krok 2a: Nowi Producenci - Motomar Import</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        .header { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
        h1 { color: #333; font-size: 24px; margin-bottom: 8px; }
        .steps { display: flex; gap: 10px; margin-top: 15px; font-size: 13px; }
        .step { padding: 8px 16px; background: #e0e0e0; border-radius: 20px; color: #666; }
        .step.active { background: #007bff; color: white; font-weight: 600; }
        .step.completed { background: #28a745; color: white; }

        .alert { padding: 15px 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; margin-bottom: 25px; }
        .alert-icon { display: inline-block; font-size: 20px; margin-right: 10px; }
        .alert-title { font-weight: 600; color: #856404; margin-bottom: 5px; }
        .alert-text { color: #856404; font-size: 14px; line-height: 1.5; }

        .error { padding: 15px 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; margin-bottom: 20px; color: #721c24; }

        .producers-list { margin-bottom: 30px; }
        .producer-item { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin-bottom: 15px; }
        .producer-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .producer-name { font-size: 18px; font-weight: 600; color: #333; }
        .producer-count { background: #007bff; color: white; padding: 4px 12px; border-radius: 12px; font-size: 13px; font-weight: 600; }

        .form-group { margin-bottom: 0; }
        label { display: block; font-size: 13px; font-weight: 600; color: #666; margin-bottom: 6px; }
        input[type="text"] { width: 100%; padding: 10px 14px; border: 2px solid #dee2e6; border-radius: 6px; font-size: 15px; transition: border-color 0.2s; }
        input[type="text"]:focus { outline: none; border-color: #007bff; }

        .help-text { font-size: 12px; color: #6c757d; margin-top: 6px; font-style: italic; }

        .actions { display: flex; gap: 15px; padding-top: 20px; border-top: 2px solid #e0e0e0; }
        button { padding: 12px 30px; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-back { background: #6c757d; color: white; }
        .btn-back:hover { background: #5a6268; }
        .btn-submit { background: #007bff; color: white; flex: 1; }
        .btn-submit:hover { background: #0056b3; }

        .summary { background: #e7f3ff; border: 1px solid #007bff; border-radius: 6px; padding: 15px; margin-bottom: 25px; }
        .summary-text { color: #004085; font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🆕 Krok 2a: Nowi Producenci</h1>
            <div class="steps">
                <span class="step completed">1. Upload CSV</span>
                <span class="step active">2a. Nowi producenci</span>
                <span class="step">2. Mapowanie</span>
                <span class="step">3. Potwierdzenie</span>
                <span class="step">4. Import</span>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="alert">
            <div class="alert-icon">⚠️</div>
            <div class="alert-title">Wykryto nowych producentów w cennikach</div>
            <div class="alert-text">
                Producenci poniżej nie istnieją w bazie danych. Możesz poprawić ich nazwy
                (np. zmienić wielkość liter, dodać pełną nazwę) przed importem.
                Nazwy zostaną zapisane i użyte dla wszystkich produktów danego producenta.
            </div>
        </div>

        <div class="summary">
            <div class="summary-text">
                📊 <strong>Podsumowanie:</strong> Wykryto <strong><?= count($newProducers) ?></strong>
                <?= count($newProducers) === 1 ? 'nowego producenta' : 'nowych producentów' ?>
                w cennikach.
            </div>
        </div>

        <form method="POST" action="producers">
            <div class="producers-list">
                <?php foreach ($newProducers as $producer): ?>
                    <div class="producer-item">
                        <div class="producer-header">
                            <div class="producer-name">
                                <?= htmlspecialchars($producer['name']) ?>
                            </div>
                            <div class="producer-count">
                                <?= $producer['count'] ?> <?= $producer['count'] === 1 ? 'produkt' : 'produktów' ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Nazwa producenta w sklepie:</label>
                            <input
                                type="text"
                                name="producer_name_<?= base64_encode($producer['name']) ?>"
                                value="<?= htmlspecialchars(ucwords(strtolower($producer['name']))) ?>"
                                placeholder="np. Nexen Tire, Sailun, Torque"
                                required
                                autocomplete="off"
                            />
                            <div class="help-text">
                                💡 Nazwa z cennika: <strong><?= htmlspecialchars($producer['name']) ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="actions">
                <button type="button" class="btn-back" onclick="if(confirm('Czy na pewno chcesz anulować? Trzeba będzie ponownie wgrać plik CSV.')) { window.location.href='/'; }">
                    ← Anuluj
                </button>
                <button type="submit" class="btn-submit">
                    Zapisz i kontynuuj mapowanie →
                </button>
            </div>
        </form>
    </div>
</body>
</html>
