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
            'ai_max_market_options',
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
        <h5 class="text-light mb-3"><i class="bi bi-sliders"></i> Analiz Kapsamı</h5>
        <label class="form-label">Analiz edilecek en fazla seçenek sayısı</label>
        <input type="number" min="2" max="200" name="ai_max_market_options" class="form-control"
               value="<?= e($s['ai_max_market_options'] ?? '24') ?>" style="max-width:180px">
        <small class="text-secondary d-block mt-2">
            Bu sayıdan fazla seçeneği olan marketler (ör. “Oyuncu Şut Çeker” — 99 seçenek) analiz dışı
            bırakılır; oranları uygulamada yine görünür. Yüksek değer maliyeti ve süreyi artırır.
        </small>
        <div class="alert alert-secondary mt-3 mb-0 small">
            <i class="bi bi-coin"></i> Market kredi maliyetleri artık
            <a href="markets.php" class="alert-link">Marketler</a> sayfasından yönetiliyor.
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
