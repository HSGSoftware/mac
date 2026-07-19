<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Settings;
use MacRadar\Services\Llm\LlmFactory;

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save' || $action === 'test') {
        foreach ([
            'ai_provider', 'gemini_api_key', 'gemini_model',
            'openai_api_key', 'openai_base_url', 'openai_model', 'analysis_prompt',
            'credit_cost_group_gol', 'credit_cost_group_handikap', 'credit_cost_group_ozel',
            'credit_cost_live_market',
        ] as $key) {
            if (isset($_POST[$key])) {
                Settings::set($key, trim($_POST[$key]));
            }
        }
        if ($action === 'test') {
            try {
                $client = LlmFactory::make();
                $testResult = ['ok' => true, 'data' => $client->test()];
            } catch (\Throwable $ex) {
                $testResult = ['ok' => false, 'error' => $ex->getMessage()];
            }
        } else {
            flash('AI ayarları kaydedildi.');
            header('Location: ai_settings.php');
            exit;
        }
    }
}

$s = Settings::all();
admin_header('AI Ayarları', 'ai_settings.php');
render_flash();

if ($testResult) {
    if ($testResult['ok']) {
        echo '<div class="alert alert-success"><strong>Bağlantı başarılı!</strong> <pre class="mb-0 mt-2">' . e(json_encode($testResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
    } else {
        echo '<div class="alert alert-danger"><strong>Test başarısız:</strong> ' . e($testResult['error']) . '</div>';
    }
}
?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="card p-4 mb-3">
        <h5 class="text-light mb-3">Aktif Sağlayıcı</h5>
        <select name="ai_provider" class="form-select mb-2" onchange="toggleProvider(this.value)">
            <?php foreach (['gemini'=>'Google Gemini','openai'=>'OpenAI','custom'=>'Custom LLM (OpenAI uyumlu)'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= ($s['ai_provider'] ?? 'gemini')===$val?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-secondary">Custom LLM için OpenAI ayarlarındaki <em>Base URL</em>'i kendi uç noktanıza çevirin (OpenRouter, Groq, Ollama, LM Studio, kendi sunucunuz vb.).</small>
    </div>

    <div class="card p-4 mb-3">
        <h5 class="text-light mb-3"><i class="bi bi-google"></i> Gemini</h5>
        <label class="form-label">API Key</label>
        <input type="password" name="gemini_api_key" class="form-control mb-2" value="<?= e($s['gemini_api_key'] ?? '') ?>" placeholder="AIza...">
        <label class="form-label">Model</label>
        <input type="text" name="gemini_model" class="form-control" value="<?= e($s['gemini_model'] ?? 'gemini-1.5-flash') ?>">
    </div>

    <div class="card p-4 mb-3">
        <h5 class="text-light mb-3"><i class="bi bi-cpu"></i> OpenAI / Custom LLM</h5>
        <label class="form-label">API Key</label>
        <input type="password" name="openai_api_key" class="form-control mb-2" value="<?= e($s['openai_api_key'] ?? '') ?>" placeholder="sk-...">
        <label class="form-label">Base URL <span class="text-warning">(custom LLM burada)</span></label>
        <input type="text" name="openai_base_url" class="form-control mb-2" value="<?= e($s['openai_base_url'] ?? 'https://api.openai.com/v1') ?>" placeholder="https://api.openai.com/v1">
        <label class="form-label">Model</label>
        <input type="text" name="openai_model" class="form-control" value="<?= e($s['openai_model'] ?? 'gpt-4o-mini') ?>" placeholder="gpt-4o-mini / llama-3.1-70b / ...">
    </div>

    <div class="card p-4 mb-3">
        <h5 class="text-light mb-3"><i class="bi bi-coin"></i> Market Kredi Maliyetleri (token)</h5>
        <p class="text-secondary small mb-3">Bir market analizi açıldığında düşecek kredi. <strong>Ana marketler (Maç Sonucu / Çifte Şans / Yarı Sonucu) her zaman ÜCRETSİZ</strong> — oranla birlikte gösterilir. Bir maç ilk açıldığında tüm marketler tek seferde üretilir; kredi yalnızca ücretli bir marketi görüntülerken düşer.</p>
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <label class="form-label">Gol Marketleri</label>
                <input type="number" min="0" name="credit_cost_group_gol" class="form-control" value="<?= e($s['credit_cost_group_gol'] ?? '1') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Handikap & Kombine</label>
                <input type="number" min="0" name="credit_cost_group_handikap" class="form-control" value="<?= e($s['credit_cost_group_handikap'] ?? '1') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Özel Marketler</label>
                <input type="number" min="0" name="credit_cost_group_ozel" class="form-control" value="<?= e($s['credit_cost_group_ozel'] ?? '1') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Canlı maç (alt sınır)</label>
                <input type="number" min="0" name="credit_cost_live_market" class="form-control" value="<?= e($s['credit_cost_live_market'] ?? '2') ?>">
            </div>
        </div>
    </div>

    <div class="card p-4 mb-3">
        <h5 class="text-light mb-3">Analiz Prompt Şablonu (opsiyonel)</h5>
        <textarea name="analysis_prompt" class="form-control" rows="4" placeholder="Boş bırakılırsa varsayılan Türkçe prompt kullanılır."><?= e($s['analysis_prompt'] ?? '') ?></textarea>
    </div>

    <button name="action" value="save" class="btn btn-success"><i class="bi bi-save"></i> Kaydet</button>
    <button name="action" value="test" class="btn btn-outline-warning"><i class="bi bi-plug"></i> Kaydet & Test Et</button>
</form>
<?php admin_footer();
