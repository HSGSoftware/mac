-- ============================================================
--  MaçRadar - Migration: Günlük KREDİ sistemi (market başına AI analizi)
--  * Her paketin günlük kredi hakkı vardır; her gün SIFIRLANIR (devretmez).
--  * Her market AYRI analiz edilir ve AYRI kredi tüketir.
--  * Canlı maç analizleri yalnızca Altın pakette (daha yüksek kredi).
--  * Oran gruplarının hangi pakete açık olduğu da ayarlardan yönetilir.
--  migrate.php bu dosyayı otomatik uygular.
-- ============================================================
SET NAMES utf8mb4;

-- Varsayılan kredi/görünürlük ayarları (admin panelinden değiştirilebilir)
INSERT INTO settings (skey, svalue) VALUES
    ('free_daily_credits',      '1'),
    ('bronz_daily_credits',     '20'),
    ('gumus_daily_credits',     '50'),
    ('altin_daily_credits',     '120'),
    ('credit_cost_market',      '1'),
    ('credit_cost_live_market', '2'),
    ('live_analysis_ttl',       '180'),
    ('group_min_tier_ana',      '0'),
    ('group_min_tier_gol',      '1'),
    ('group_min_tier_handikap', '2'),
    ('group_min_tier_ozel',     '3'),
    ('ai_web_search',           '1')
ON DUPLICATE KEY UPDATE skey = skey;

-- Market başına AI analizleri (maç + market başına tek satır; canlıda tazelenir)
CREATE TABLE IF NOT EXISTS market_analyses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id      BIGINT UNSIGNED NOT NULL,
    market_key    VARCHAR(64) NOT NULL,     -- 'MS' veya scraped market anahtarı (m_<hash>)
    market_label  VARCHAR(160) NOT NULL,
    is_live       TINYINT(1) NOT NULL DEFAULT 0,  -- canlı maçta üretildi
    status        ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
    provider      VARCHAR(40) DEFAULT NULL,
    model_name    VARCHAR(80) DEFAULT NULL,
    result        LONGTEXT DEFAULT NULL,    -- JSON: secenekler[], tavsiye, guven, ozet, kaynaklar
    error_message VARCHAR(500) DEFAULT NULL,
    token_usage   INT UNSIGNED DEFAULT NULL,
    created_by    BIGINT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ma (match_id, market_key),
    KEY idx_ma_match (match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kullanıcının kredi harcayarak açtığı market analizleri
-- (aynı maçın aynı marketini tekrar görüntülemek ücretsizdir;
--  canlıda tazelik süresi dolunca yeni analiz yeni kredi ister)
CREATE TABLE IF NOT EXISTS user_unlocks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    match_id      BIGINT UNSIGNED NOT NULL,
    item_type     VARCHAR(20) NOT NULL,             -- 'market'
    item_key      VARCHAR(64) NOT NULL DEFAULT '',  -- market anahtarı
    credits_spent INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unlock (user_id, match_id, item_type, item_key),
    KEY idx_unlock_user_match (user_id, match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kullanıcı kredi sayaçları (ALTER'lar en sonda: dosya tekrar
-- çalıştırılırsa üstteki idempotent ifadeler yine de uygulanır)
ALTER TABLE users ADD COLUMN credits_used INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN credits_date DATE DEFAULT NULL;
-- Admin'in kullanıcıya eklediği bonus kredi: günlük hak bittikten sonra
-- harcanır, GÜNLÜK SIFIRLANMAZ (bitene kadar durur)
ALTER TABLE users ADD COLUMN bonus_credits INT UNSIGNED NOT NULL DEFAULT 0;
