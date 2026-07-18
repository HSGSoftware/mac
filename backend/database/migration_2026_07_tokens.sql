-- ============================================================
--  MaçRadar - Migration: Günlük TOKEN sistemi
--  * Her paketin günlük token hakkı vardır; gün değişince SIFIRLANIR
--    (devretmez / birikmez).
--  * Market grupları ve AI analizleri token harcayarak açılır.
--  * user_unlocks: kullanıcının token harcayarak açtığı içerikler
--    (tekrar görüntüleme ücretsizdir).
--  migrate.php bu dosyayı otomatik uygular.
-- ============================================================
SET NAMES utf8mb4;

-- Varsayılan token ayarları (admin panelinden değiştirilebilir)
INSERT INTO settings (skey, svalue) VALUES
    ('free_daily_tokens',        '10'),
    ('bronz_daily_tokens',       '100'),
    ('gumus_daily_tokens',       '250'),
    ('altin_daily_tokens',       '600'),
    ('token_cost_group_ana',      '10'),
    ('token_cost_group_gol',      '15'),
    ('token_cost_group_handikap', '20'),
    ('token_cost_group_ozel',     '25'),
    ('token_cost_analysis',       '25'),
    ('token_cost_live_analysis',  '40')
ON DUPLICATE KEY UPDATE skey = skey;

-- Token ile açılan içerikler (market grubu / AI analizi)
CREATE TABLE IF NOT EXISTS user_unlocks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    match_id     BIGINT UNSIGNED NOT NULL,
    item_type    VARCHAR(20) NOT NULL,             -- market_group / analysis
    item_key     VARCHAR(30) NOT NULL DEFAULT '',  -- grup anahtarı (ana/gol/handikap/ozel)
    tokens_spent INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unlock (user_id, match_id, item_type, item_key),
    KEY idx_unlock_user_match (user_id, match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kullanıcı token sayaçları (ALTER'lar en sonda: dosya tekrar
-- çalıştırılırsa üstteki idempotent ifadeler yine de uygulanır)
ALTER TABLE users ADD COLUMN tokens_used INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN tokens_date DATE DEFAULT NULL;
