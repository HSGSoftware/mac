-- =====================================================================
-- Bildirimler + takım amblemleri + market bazlı kredi ayarları
--
-- Bu migration tekrar tekrar çalıştırılabilir: tablo/kolon eklemeleri
-- migrate.php tarafından "zaten var" hataları yutularak uygulanır.
-- =====================================================================

-- 1) Uygulama içi bildirimler ------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'info',
    title VARCHAR(160) NOT NULL,
    body VARCHAR(500) DEFAULT NULL,
    match_id INT DEFAULT NULL,
    data JSON DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_user_created (user_id, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Takım amblemi arama durumu ---------------------------------------------
-- logo_url zaten schema.sql'de var; bu kolon "aradık ama bulamadık" durumunu
-- işaretler ki her cron çalışmasında aynı takım için istek atılmasın.
ALTER TABLE teams ADD COLUMN logo_checked_at TIMESTAMP NULL DEFAULT NULL;

-- 3) Yeni ayarlar ------------------------------------------------------------
INSERT INTO settings (skey, svalue) VALUES
    -- Market bazlı kredi maliyeti override'ı: {"268": 2, "777": 3} (MTID => kredi)
    ('credit_cost_markets', '{}'),
    -- Analize gönderilecek en fazla seçenek sayısı; üstü (oyuncu marketleri gibi)
    -- prompt'u şişirdiği için analiz dışı bırakılır, oranları yine gösterilir.
    ('ai_max_market_options', '24'),
    -- Takım amblemi kaynağı
    ('sportsdb_api_key', '3'),
    ('team_logo_retry_days', '30'),
    -- Analiz hazır olduğunda bildirim gönder
    ('notify_on_analysis_ready', '1')
ON DUPLICATE KEY UPDATE skey = skey;
