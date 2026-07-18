<?php
require __DIR__ . '/bootstrap.php';
$admin = admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Core\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'general') {
        Settings::set('announcement', trim($_POST['announcement'] ?? ''));
        flash('Genel ayarlar kaydedildi.');
    } elseif ($action === 'credits') {
        $creditKeys = [
            'free_daily_credits' => 1, 'bronz_daily_credits' => 20,
            'gumus_daily_credits' => 50, 'altin_daily_credits' => 120,
            'credit_cost_market' => 1, 'credit_cost_live_market' => 2,
            'live_analysis_ttl' => 180,
        ];
        foreach ($creditKeys as $key => $def) {
            Settings::set($key, max(0, (int) ($_POST[$key] ?? $def)));
        }
        foreach (['ana', 'gol', 'handikap', 'ozel'] as $g) {
            $t = (int) ($_POST['group_min_tier_' . $g] ?? 0);
            Settings::set('group_min_tier_' . $g, max(0, min(3, $t)));
        }
        Settings::set('ai_web_search', isset($_POST['ai_web_search']) ? '1' : '0');
        flash('Kredi ayarları kaydedildi.');
    } elseif ($action === 'market_names') {
        // Grup adları
        foreach (['ana', 'gol', 'handikap', 'ozel'] as $g) {
            Settings::set('group_name_' . $g, trim($_POST['group_name_' . $g] ?? ''));
        }
        // Market adı eşlemesi: her satır "Orijinal Ad => Yeni Ad"
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($_POST['market_name_overrides'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=>')) {
                continue;
            }
            [$orig, $new] = array_map('trim', explode('=>', $line, 2));
            if ($orig !== '' && $new !== '') {
                $map[$orig] = $new;
            }
        }
        Settings::set('market_name_overrides', $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : '');
        flash('Market isimleri kaydedildi.');
    } elseif ($action === 'password') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) {
            flash('Şifre en az 6 karakter olmalı.', 'danger');
        } else {
            Database::execute('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            flash('Admin şifresi güncellendi.');
        }
    }
    header('Location: settings.php');
    exit;
}

$s = Settings::all();
admin_header('Genel Ayarlar', 'settings.php');
render_flash();
?>
<div class="card p-4 mb-3">
    <h5 class="text-light mb-3">Uygulama Ayarları</h5>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label class="form-label">Duyuru mesajı (uygulamada gösterilir, boş = kapalı)</label>
        <textarea name="announcement" class="form-control mb-3" rows="2"><?= e($s['announcement'] ?? '') ?></textarea>
        <button name="action" value="general" class="btn btn-success"><i class="bi bi-save"></i> Kaydet</button>
    </form>
</div>
<?php
$tierOptions = [0 => 'Ücretsiz', 1 => 'Bronz', 2 => 'Gümüş', 3 => 'Altın'];
$tierSelect = function (string $name, $current) use ($tierOptions) {
    $html = '<select name="' . $name . '" class="form-select mb-3">';
    foreach ($tierOptions as $val => $label) {
        $sel = ((int) $current === $val) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '+</option>';
    }
    return $html . '</select>';
};
?>
<div class="card p-4 mb-3">
    <h5 class="text-light mb-3">Günlük Kredi Ayarları</h5>
    <p class="text-secondary" style="font-size:13px">Krediler her gün sıfırlanır; ertesi güne devretmez.
       HER MARKET AYRI analiz edilir ve ayrı kredi tüketir. Canlı maç analizleri yalnızca Altın pakettedir.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="row">
            <div class="col-md-3"><label class="form-label">Ücretsiz — günlük kredi</label>
                <input type="number" name="free_daily_credits" class="form-control mb-3" value="<?= e($s['free_daily_credits'] ?? '1') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Bronz — günlük kredi</label>
                <input type="number" name="bronz_daily_credits" class="form-control mb-3" value="<?= e($s['bronz_daily_credits'] ?? '20') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Gümüş — günlük kredi</label>
                <input type="number" name="gumus_daily_credits" class="form-control mb-3" value="<?= e($s['gumus_daily_credits'] ?? '50') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Altın — günlük kredi</label>
                <input type="number" name="altin_daily_credits" class="form-control mb-3" value="<?= e($s['altin_daily_credits'] ?? '120') ?>" min="0"></div>
        </div>
        <div class="row">
            <div class="col-md-3"><label class="form-label">Market analizi maliyeti (kredi)</label>
                <input type="number" name="credit_cost_market" class="form-control mb-3" value="<?= e($s['credit_cost_market'] ?? '1') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Canlı market analizi maliyeti (kredi)</label>
                <input type="number" name="credit_cost_live_market" class="form-control mb-3" value="<?= e($s['credit_cost_live_market'] ?? '2') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Canlı analiz tazelik süresi (sn)</label>
                <input type="number" name="live_analysis_ttl" class="form-control mb-3" value="<?= e($s['live_analysis_ttl'] ?? '180') ?>" min="30"></div>
            <div class="col-md-3"><label class="form-label d-block">İnternet araştırması (Gemini)</label>
                <div class="form-check form-switch mt-2 mb-3">
                    <input class="form-check-input" type="checkbox" name="ai_web_search" value="1"
                        <?= (($s['ai_web_search'] ?? '1') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label text-secondary">Analizde web araması yap</label>
                </div></div>
        </div>
        <h6 class="text-light mt-2 mb-2">Oran gruplarını görebilecek minimum paket</h6>
        <div class="row">
            <div class="col-md-3"><label class="form-label">Ana Marketler</label>
                <?= $tierSelect('group_min_tier_ana', $s['group_min_tier_ana'] ?? 0) ?></div>
            <div class="col-md-3"><label class="form-label">Gol Marketleri</label>
                <?= $tierSelect('group_min_tier_gol', $s['group_min_tier_gol'] ?? 1) ?></div>
            <div class="col-md-3"><label class="form-label">Handikap &amp; Kombine</label>
                <?= $tierSelect('group_min_tier_handikap', $s['group_min_tier_handikap'] ?? 2) ?></div>
            <div class="col-md-3"><label class="form-label">Özel Marketler</label>
                <?= $tierSelect('group_min_tier_ozel', $s['group_min_tier_ozel'] ?? 3) ?></div>
        </div>
        <button name="action" value="credits" class="btn btn-success"><i class="bi bi-save"></i> Kredi Ayarlarını Kaydet</button>
    </form>
</div>
<?php
// Kayıtlı market adı eşlemesini "Orijinal => Yeni" satırlarına çevir
$ovLines = '';
$ovMap = json_decode((string) ($s['market_name_overrides'] ?? ''), true);
if (is_array($ovMap)) {
    foreach ($ovMap as $orig => $new) {
        $ovLines .= $orig . ' => ' . $new . "\n";
    }
}
?>
<div class="card p-4 mb-3">
    <h5 class="text-light mb-3">Market İsimleri</h5>
    <p class="text-secondary" style="font-size:13px">Uygulamada gösterilen grup ve market adlarını buradan
       değiştirebilirsiniz. Boş bırakılan alanlar varsayılan adıyla gösterilir. Eşleme yalnızca GÖRÜNEN adı
       değiştirir; analizler ve gruplama orijinal ada göre çalışmaya devam eder.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="row">
            <div class="col-md-3"><label class="form-label">"Ana Marketler" grubunun adı</label>
                <input type="text" name="group_name_ana" class="form-control mb-3" placeholder="Ana Marketler" value="<?= e($s['group_name_ana'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">"Gol Marketleri" grubunun adı</label>
                <input type="text" name="group_name_gol" class="form-control mb-3" placeholder="Gol Marketleri" value="<?= e($s['group_name_gol'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">"Handikap &amp; Kombine" grubunun adı</label>
                <input type="text" name="group_name_handikap" class="form-control mb-3" placeholder="Handikap &amp; Kombine" value="<?= e($s['group_name_handikap'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">"Özel Marketler" grubunun adı</label>
                <input type="text" name="group_name_ozel" class="form-control mb-3" placeholder="Özel Marketler" value="<?= e($s['group_name_ozel'] ?? '') ?>"></div>
        </div>
        <label class="form-label">Market adı eşlemesi — her satıra bir kural: <code>Orijinal Ad =&gt; Yeni Ad</code></label>
        <textarea name="market_name_overrides" class="form-control mb-2" rows="6"
            placeholder="Maç Sonucu => Maç Kazananı&#10;Karşılıklı Gol => KG Var/Yok"><?= e(trim($ovLines)) ?></textarea>
        <p class="text-secondary" style="font-size:12px">Orijinal adlar, kaynaktan (Mackolik) gelen market adlarıdır ve
           maç detayındaki market listesinde görülür. Örn: <code>2,5 Gol Alt/Üst =&gt; 2.5 Gol Sınırı</code></p>
        <button name="action" value="market_names" class="btn btn-success"><i class="bi bi-save"></i> Market İsimlerini Kaydet</button>
    </form>
</div>
<div class="card p-4">
    <h5 class="text-light mb-3">Admin Şifresi Değiştir</h5>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label class="form-label">Yeni şifre</label>
        <input type="password" name="new_password" class="form-control mb-3" required>
        <button name="action" value="password" class="btn btn-outline-warning">Şifreyi Güncelle</button>
    </form>
</div>
<?php admin_footer();
