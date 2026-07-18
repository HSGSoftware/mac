<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Core\Settings;
use MacRadar\Services\MackolikScraper;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_selectors') {
        foreach ([
            'scraper_goapi_url','scraper_user_agent',
            'scraper_fixtures_json_url','scraper_fixtures_html_url',
            'scraper_match_json_url',
            'mk_idx_home','mk_idx_away','mk_idx_code','mk_idx_time','mk_idx_date',
            'mk_idx_league','mk_idx_league_name','mk_idx_ms_home','mk_idx_ms_away',
            'mk_idx_sport','mk_idx_odds',
            'xpath_fixture_row','xpath_home','xpath_away','xpath_time','xpath_ms1','xpath_msx','xpath_ms2',
        ] as $key) {
            if (isset($_POST[$key])) {
                Settings::set($key, trim($_POST[$key]));
            }
        }
        flash('Scraper ayarları kaydedildi.');
    } elseif ($action === 'run') {
        try {
            $res = (new MackolikScraper())->fetchFixtures(date('Y-m-d'));
            flash("Manuel çalıştırma: {$res['count']} maç ({$res['source']})");
        } catch (\Throwable $ex) {
            flash('Hata: ' . $ex->getMessage(), 'danger');
        }
    }
    header('Location: scraper.php');
    exit;
}

$s = Settings::all();
$logs = Database::fetchAll('SELECT * FROM scrape_logs ORDER BY id DESC LIMIT 30');

admin_header('Scraper', 'scraper.php');
render_flash();
?>
<form method="post" class="mb-4">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <button name="action" value="run" class="btn btn-success"><i class="bi bi-play-fill"></i> Bugünü şimdi çek</button>
</form>

<div class="card p-4 mb-4">
    <h5 class="text-light mb-3">Kaynak Ayarları</h5>
    <p class="text-secondary small">Birincil kaynak <strong>goapi.mackolik.com/livedata</strong>. <code>{date_dmy}</code> = GG/AA/YYYY, <code>{date}</code> = YYYY-AA-GG, <code>{id}</code> yer tutucuları desteklenir. Mackolik dizi formatı değişirse aşağıdaki indeksleri güncelleyin — kod değiştirmeye gerek yok.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="row g-2">
            <div class="col-md-8"><label class="form-label">goapi Fixtures URL (birincil)</label><input name="scraper_goapi_url" class="form-control" value="<?= e($s['scraper_goapi_url'] ?? 'http://goapi.mackolik.com/livedata?date={date_dmy}') ?>"></div>
            <div class="col-md-4"><label class="form-label">User-Agent</label><input name="scraper_user_agent" class="form-control" value="<?= e($s['scraper_user_agent'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Özel Fixtures JSON URL (opsiyonel)</label><input name="scraper_fixtures_json_url" class="form-control" value="<?= e($s['scraper_fixtures_json_url'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Fixtures HTML URL (son çare)</label><input name="scraper_fixtures_html_url" class="form-control" value="<?= e($s['scraper_fixtures_html_url'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Maç detay/istatistik JSON URL</label><input name="scraper_match_json_url" class="form-control" value="<?= e($s['scraper_match_json_url'] ?? '') ?>" placeholder="https://.../match/{id}"></div>
        </div>
        <h6 class="text-light mt-3">goapi Dizi İndeksleri (boş = varsayılan)</h6>
        <p class="text-secondary small">Scraper her çekimde ilk maçın ham dizisini "mackolik_debug" log'una yazar; oradaki konumlara göre gerekirse düzeltin. Varsayılanlar: ev=2, dep=4, kod=14, saat=16, tarih=35, lig=36, lig_adı=9, MS_ev=29, MS_dep=30, spor=23, oran=1.</p>
        <div class="row g-2">
            <?php foreach (['home'=>'Ev','away'=>'Dep','code'=>'Kod','time'=>'Saat','date'=>'Tarih','league'=>'Lig','league_name'=>'Lig adı','ms_home'=>'MS ev','ms_away'=>'MS dep','sport'=>'Spor','odds'=>'Oran'] as $k=>$lbl): ?>
            <div class="col-6 col-md-2"><label class="form-label small"><?= $lbl ?></label><input name="mk_idx_<?= $k ?>" class="form-control form-control-sm" value="<?= e($s['mk_idx_'.$k] ?? '') ?>" placeholder="varsayılan"></div>
            <?php endforeach; ?>
        </div>
        <h6 class="text-light mt-3">HTML Yedek — XPath Seçicileri</h6>
        <div class="row g-2">
            <div class="col-md-12"><label class="form-label">Maç satırı</label><input name="xpath_fixture_row" class="form-control" value="<?= e($s['xpath_fixture_row'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Ev sahibi</label><input name="xpath_home" class="form-control" value="<?= e($s['xpath_home'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Deplasman</label><input name="xpath_away" class="form-control" value="<?= e($s['xpath_away'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Saat</label><input name="xpath_time" class="form-control" value="<?= e($s['xpath_time'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">MS1</label><input name="xpath_ms1" class="form-control" value="<?= e($s['xpath_ms1'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">MSX</label><input name="xpath_msx" class="form-control" value="<?= e($s['xpath_msx'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">MS2</label><input name="xpath_ms2" class="form-control" value="<?= e($s['xpath_ms2'] ?? '') ?>"></div>
        </div>
        <button name="action" value="save_selectors" class="btn btn-success mt-3"><i class="bi bi-save"></i> Kaydet</button>
    </form>
</div>

<div class="card p-3">
    <h5 class="text-light mb-3">Son Loglar</h5>
    <table class="table table-sm">
        <thead><tr><th>İş</th><th>Durum</th><th>Öğe</th><th>Süre</th><th>Mesaj</th><th>Tarih</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td><?= e($l['job']) ?></td>
                <td><span class="badge bg-<?= $l['status']==='success'?'success':($l['status']==='partial'?'warning':'danger') ?>"><?= e($l['status']) ?></span></td>
                <td><?= (int)$l['items_count'] ?></td>
                <td><?= $l['duration_ms']!==null ? (int)$l['duration_ms'].'ms' : '-' ?></td>
                <td style="max-width:520px;word-break:break-all;"><small><?= e(mb_substr((string)$l['message'],0,1000)) ?></small></td>
                <td><small><?= e($l['created_at']) ?></small></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" class="text-center text-secondary py-3">Log yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php admin_footer();
