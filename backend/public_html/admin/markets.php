<?php
/**
 * Admin > Marketler
 *
 * Tek yerden market yönetimi:
 *  - Grup başına varsayılan kredi maliyeti (Ana marketler varsayılan ÜCRETSİZ)
 *  - Market tipi (MTID) başına kredi override'ı ve görünen ad değişikliği
 *  - Nesine market isim sözlüğünü güncelleme
 *
 * Listede bültende GERÇEKTEN kullanılan market tipleri gösterilir (sözlükteki
 * 700+ tanımın tamamı değil), böylece tek tek uğraşmadan fiyatlandırma yapılır.
 */
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;
use MacRadar\Services\MackolikScraper;
use MacRadar\Services\MarketDictionary;

$refreshResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_groups') {
        foreach (['ana', 'gol', 'handikap', 'ozel'] as $g) {
            $key = 'credit_cost_group_' . $g;
            if (isset($_POST[$key])) {
                Settings::set($key, (string) max(0, (int) $_POST[$key]));
            }
        }
        if (isset($_POST['credit_cost_live_market'])) {
            Settings::set('credit_cost_live_market', (string) max(0, (int) $_POST['credit_cost_live_market']));
        }
        flash('Grup maliyetleri kaydedildi.');
        header('Location: markets.php');
        exit;
    }

    if ($action === 'save_markets') {
        $costs = Credits::marketCostOverrides();
        $names = [];
        $rawNames = (string) Settings::get('mk_market_names', '');
        if ($rawNames !== '') {
            $decoded = json_decode($rawNames, true);
            if (is_array($decoded)) {
                $names = $decoded;
            }
        }
        $overrides = (array) ($_POST['cost'] ?? []);
        foreach ($overrides as $mtid => $val) {
            $mtid = (int) $mtid;
            $val = trim((string) $val);
            if ($val === '') {
                unset($costs[$mtid]);   // boş = grup varsayılanını kullan
            } else {
                $costs[$mtid] = max(0, (int) $val);
            }
        }
        foreach ((array) ($_POST['name'] ?? []) as $mtid => $val) {
            $mtid = (int) $mtid;
            $val = trim((string) $val);
            $default = MarketDictionary::MARKETS[$mtid][0] ?? '';
            if ($val === '' || $val === $default) {
                unset($names[(string) $mtid]);
            } else {
                $names[(string) $mtid] = $val;
            }
        }
        Settings::set('credit_cost_markets', json_encode($costs, JSON_UNESCAPED_UNICODE));
        Settings::set('mk_market_names', json_encode($names, JSON_UNESCAPED_UNICODE));
        flash('Market ayarları kaydedildi. Yeni fiyatlar anında geçerlidir.');
        header('Location: markets.php');
        exit;
    }

    if ($action === 'refresh_names') {
        try {
            $refreshResult = ['ok' => true, 'data' => (new MackolikScraper())->refreshMarketNames()];
        } catch (\Throwable $ex) {
            $refreshResult = ['ok' => false, 'error' => $ex->getMessage()];
        }
    }
}

$s = Settings::all();
$costOverrides = Credits::marketCostOverrides();
$nameOverrides = [];
$rawNames = (string) Settings::get('mk_market_names', '');
if ($rawNames !== '') {
    $decoded = json_decode($rawNames, true);
    if (is_array($decoded)) {
        $nameOverrides = $decoded;
    }
}

/**
 * Bültende kullanılan market tiplerini topla: son maçların markets JSON'undan
 * MTID + örnek ad + kaç maçta göründüğü.
 */
$used = [];
try {
    $rows = Database::fetchAll(
        "SELECT ms.data
         FROM match_stats ms
         JOIN matches m ON m.id = ms.match_id
         WHERE ms.type = 'markets' AND m.start_time >= NOW() - INTERVAL 3 DAY
         ORDER BY ms.match_id DESC
         LIMIT 400"
    );
} catch (\Throwable $e) {
    $rows = [];
}
foreach ($rows as $r) {
    $list = json_decode((string) $r['data'], true);
    if (!is_array($list)) {
        continue;
    }
    foreach ($list as $mk) {
        if (!is_array($mk)) {
            continue;
        }
        $mtid = (int) ($mk['mtid'] ?? 0);
        if ($mtid <= 0) {
            continue;
        }
        if (!isset($used[$mtid])) {
            $used[$mtid] = [
                'mtid' => $mtid,
                'sample' => (string) ($mk['ad'] ?? ''),
                'count' => 0,
                'options' => count($mk['secenekler'] ?? []),
            ];
        }
        $used[$mtid]['count']++;
        $used[$mtid]['options'] = max($used[$mtid]['options'], count($mk['secenekler'] ?? []));
    }
}
uasort($used, fn($a, $b) => $b['count'] <=> $a['count']);

$maxOpts = max(2, (int) Settings::get('ai_max_market_options', 24));

admin_header('Marketler', 'markets.php');
render_flash();

if ($refreshResult) {
    if ($refreshResult['ok']) {
        $d = $refreshResult['data'];
        echo '<div class="alert alert-success"><strong>Sözlük güncellendi.</strong> '
            . (int) $d['count'] . ' market adı alındı'
            . ((int) $d['new'] > 0 ? ', bunlardan <strong>' . (int) $d['new'] . '</strong> tanesi kodda tanımlı değildi' : '')
            . '.</div>';
    } else {
        echo '<div class="alert alert-danger"><strong>Güncelleme başarısız:</strong> '
            . e($refreshResult['error']) . '</div>';
    }
}
?>

<div class="card p-4 mb-3">
    <h5 class="text-light mb-3"><i class="bi bi-coin"></i> Grup Varsayılan Maliyetleri</h5>
    <p class="text-secondary small mb-3">
        Bir market analizi ilk kez görüntülendiğinde düşecek kredi. Bir maç açıldığında
        <strong>tüm marketler tek AI çağrısıyla birlikte</strong> üretilir; kredi yalnızca
        ücretli bir marketi görüntülerken düşer ve aynı marketi tekrar açmak ücretsizdir.
        <strong>Ana marketleri 0 bırakın</strong> — Maç Sonucu oranla birlikte gösterildiği için
        ayrıca ücretlendirilmesi kullanıcıyı rahatsız eder.
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="row g-3">
            <?php foreach (['ana' => 'Ana Marketler (MS)', 'gol' => 'Gol Marketleri',
                            'handikap' => 'Handikap & Kombine', 'ozel' => 'Özel Marketler'] as $g => $label): ?>
                <div class="col-6 col-md-2">
                    <label class="form-label small"><?= e($label) ?></label>
                    <input type="number" min="0" name="credit_cost_group_<?= $g ?>" class="form-control"
                           value="<?= e((string) ($s['credit_cost_group_' . $g] ?? (string) (Credits::GROUP_COST_DEFAULTS[$g] ?? 1))) ?>">
                </div>
            <?php endforeach; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small">Canlı maç (alt sınır)</label>
                <input type="number" min="0" name="credit_cost_live_market" class="form-control"
                       value="<?= e((string) ($s['credit_cost_live_market'] ?? '2')) ?>">
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end">
                <button name="action" value="save_groups" class="btn btn-success w-100">
                    <i class="bi bi-save"></i> Kaydet
                </button>
            </div>
        </div>
    </form>
</div>

<div class="card p-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h5 class="text-light mb-0"><i class="bi bi-list-ul"></i> Market Tipleri</h5>
        <form method="post" class="m-0">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button name="action" value="refresh_names" class="btn btn-outline-info btn-sm">
                <i class="bi bi-arrow-repeat"></i> Nesine'den isimleri güncelle
            </button>
        </form>
    </div>
    <p class="text-secondary small mb-3">
        Son 3 günün bülteninde görülen market tipleri (en sık kullanılan üstte).
        <strong>Kredi</strong> boş bırakılırsa grubun varsayılanı geçerli olur.
        <strong>Ad</strong> alanı yalnızca uygulamada görünen adı değiştirir; analiz ve
        gruplama orijinal ada göre yapılmaya devam eder.
        Kodda <?= count(MarketDictionary::MARKETS) ?> market tipi tanımlı.
    </p>

    <?php if (!$used): ?>
        <div class="alert alert-warning mb-0">
            Henüz market verisi yok. <a href="scraper.php" class="alert-link">Scraper</a> sayfasından
            bülteni çekin, sonra buraya dönün.
        </div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="table-responsive" style="max-height:640px">
                <table class="table table-dark table-sm align-middle mb-0">
                    <thead class="sticky-top" style="background:#1b263b">
                        <tr>
                            <th style="width:70px">MTID</th>
                            <th>Market</th>
                            <th style="width:90px" class="text-center">Grup</th>
                            <th style="width:80px" class="text-center">Seçenek</th>
                            <th style="width:70px" class="text-center">Maç</th>
                            <th style="width:110px">Kredi</th>
                            <th style="width:260px">Görünen ad (opsiyonel)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($used as $u):
                        $mtid = $u['mtid'];
                        $dictName = MarketDictionary::MARKETS[$mtid][0] ?? null;
                        $shown = $u['sample'] !== '' ? $u['sample'] : ($dictName ?? ('Market #' . $mtid));
                        $group = Credits::groupKeyForMarketName($u['sample'] !== '' ? $u['sample'] : (string) $dictName);
                        $groupCost = Credits::marketCostFor($group);
                        $override = $costOverrides[$mtid] ?? null;
                        $tooMany = $u['options'] > $maxOpts;
                        ?>
                        <tr<?= $tooMany ? ' class="opacity-75"' : '' ?>>
                            <td class="text-secondary"><?= $mtid ?></td>
                            <td>
                                <?= e($shown) ?>
                                <?php if ($dictName === null): ?>
                                    <span class="badge bg-warning text-dark ms-1">sözlükte yok</span>
                                <?php endif; ?>
                                <?php if ($tooMany): ?>
                                    <span class="badge bg-secondary ms-1" title="Seçenek sayısı sınırı aştığı için AI analizi yapılmaz">analiz dışı</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><span class="badge bg-dark border"><?= e($group) ?></span></td>
                            <td class="text-center text-secondary"><?= (int) $u['options'] ?></td>
                            <td class="text-center text-secondary"><?= (int) $u['count'] ?></td>
                            <td>
                                <input type="number" min="0" class="form-control form-control-sm"
                                       name="cost[<?= $mtid ?>]"
                                       value="<?= $override !== null ? (int) $override : '' ?>"
                                       placeholder="<?= (int) $groupCost ?>">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="name[<?= $mtid ?>]"
                                       value="<?= e((string) ($nameOverrides[(string) $mtid] ?? '')) ?>"
                                       placeholder="<?= e((string) ($dictName ?? '')) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button name="action" value="save_markets" class="btn btn-success mt-3">
                <i class="bi bi-save"></i> Market ayarlarını kaydet
            </button>
        </form>
    <?php endif; ?>
</div>

<?php admin_footer();
