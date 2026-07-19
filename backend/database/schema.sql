-- ============================================================
--  MaçRadar - Veritabanı Şeması (MySQL 5.7+ / MariaDB 10.3+)
--  Karakter seti: utf8mb4 (Türkçe karakter + emoji desteği)
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------- Kullanıcılar ----------
CREATE TABLE IF NOT EXISTS users (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email              VARCHAR(190) NOT NULL,
    password_hash      VARCHAR(255) NOT NULL,
    name               VARCHAR(120) DEFAULT NULL,
    plan               VARCHAR(20) NOT NULL DEFAULT 'free', -- free / bronz / gumus / altin
    premium_until      DATETIME DEFAULT NULL,
    daily_analysis_count INT UNSIGNED NOT NULL DEFAULT 0,
    counter_date       DATE DEFAULT NULL,
    credits_used       INT UNSIGNED NOT NULL DEFAULT 0,  -- bugün harcanan kredi
    credits_date       DATE DEFAULT NULL,                -- kredi sayacının günü (gün değişince sıfırlanır)
    bonus_credits      INT UNSIGNED NOT NULL DEFAULT 0,  -- admin'in eklediği bonus kredi (günlük sıfırlanmaz)
    fcm_token          VARCHAR(255) DEFAULT NULL,
    is_banned          TINYINT(1) NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Ligler ----------
CREATE TABLE IF NOT EXISTS leagues (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mackolik_id  VARCHAR(64) DEFAULT NULL,
    name         VARCHAR(160) NOT NULL,
    country      VARCHAR(120) DEFAULT NULL,
    logo_url     VARCHAR(255) DEFAULT NULL,
    priority     INT NOT NULL DEFAULT 100,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leagues_mackolik (mackolik_id),
    KEY idx_leagues_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Takımlar ----------
CREATE TABLE IF NOT EXISTS teams (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mackolik_id  VARCHAR(64) DEFAULT NULL,
    name         VARCHAR(160) NOT NULL,
    logo_url     VARCHAR(255) DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_mackolik (mackolik_id),
    KEY idx_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Maçlar ----------
CREATE TABLE IF NOT EXISTS matches (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mackolik_id   VARCHAR(64) DEFAULT NULL,
    league_id     BIGINT UNSIGNED DEFAULT NULL,
    home_team_id  BIGINT UNSIGNED DEFAULT NULL,
    away_team_id  BIGINT UNSIGNED DEFAULT NULL,
    iddaa_code    VARCHAR(32) DEFAULT NULL,
    start_time    DATETIME DEFAULT NULL,
    status        ENUM('scheduled','live','finished','postponed','cancelled') NOT NULL DEFAULT 'scheduled',
    minute        VARCHAR(16) DEFAULT NULL,
    ms_home       TINYINT UNSIGNED DEFAULT NULL,
    ms_away       TINYINT UNSIGNED DEFAULT NULL,
    ht_home       TINYINT UNSIGNED DEFAULT NULL,
    ht_away       TINYINT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_matches_mackolik (mackolik_id),
    KEY idx_matches_start (start_time),
    KEY idx_matches_status (status),
    KEY idx_matches_league (league_id),
    CONSTRAINT fk_matches_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_home   FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_away   FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Oranlar (her güncelleme yeni satır; en güncel is_latest=1) ----------
CREATE TABLE IF NOT EXISTS odds (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id     BIGINT UNSIGNED NOT NULL,
    market       VARCHAR(32) NOT NULL,          -- MS1, MSX, MS2, CS1X, CS12, CSX2, ALT25, UST25, KGVAR, KGYOK, ...
    value        DECIMAL(6,2) NOT NULL,
    is_latest    TINYINT(1) NOT NULL DEFAULT 1,
    fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_odds_match_latest (match_id, is_latest),
    KEY idx_odds_market (market),
    CONSTRAINT fk_odds_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Maç istatistikleri (AI girdisi) ----------
CREATE TABLE IF NOT EXISTS match_stats (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id    BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(32) NOT NULL,           -- h2h, form_home, form_away, standings, injuries
    data        JSON NOT NULL,
    fetched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stats_match_type (match_id, type),
    CONSTRAINT fk_stats_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AI Analizleri (önbellek) ----------
CREATE TABLE IF NOT EXISTS analyses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id      BIGINT UNSIGNED NOT NULL,
    provider      VARCHAR(32) NOT NULL,          -- gemini, openai, custom
    model_name    VARCHAR(120) DEFAULT NULL,
    status        ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
    result        JSON DEFAULT NULL,             -- {markets:[{market,oran,olasilik,deger_var_mi,gerekce}], ...}
    general_note  TEXT DEFAULT NULL,
    safest_pick   VARCHAR(32) DEFAULT NULL,
    surprise_level VARCHAR(16) DEFAULT NULL,     -- dusuk/orta/yuksek
    is_risky      TINYINT(1) NOT NULL DEFAULT 0,
    token_usage   INT UNSIGNED DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_by    BIGINT UNSIGNED DEFAULT NULL,  -- ilk isteyen kullanıcı
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_analyses_match (match_id, status),
    CONSTRAINT fk_analyses_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Tahmin isabet takibi ----------
CREATE TABLE IF NOT EXISTS analysis_results (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    analysis_id   BIGINT UNSIGNED NOT NULL,
    match_id      BIGINT UNSIGNED NOT NULL,
    market        VARCHAR(32) NOT NULL,
    predicted_prob DECIMAL(5,2) DEFAULT NULL,
    was_correct   TINYINT(1) DEFAULT NULL,       -- NULL: henüz bilinmiyor
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ar_market (market),
    CONSTRAINT fk_ar_analysis FOREIGN KEY (analysis_id) REFERENCES analyses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Kullanıcı analiz geçmişi ("Analizlerim" ekranı) ----------
CREATE TABLE IF NOT EXISTS user_analysis_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    match_id        BIGINT UNSIGNED NOT NULL,
    first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_viewed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_uah (user_id, match_id),
    KEY idx_uah_user (user_id, last_viewed_at),
    CONSTRAINT fk_uah_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uah_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Kullanıcı favorileri ----------
CREATE TABLE IF NOT EXISTS user_favorites (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    match_id   BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fav (user_id, match_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Market başına AI analizleri (kredi sistemi) ----------
-- Her market ayrı bir AI çağrısıyla analiz edilir; maç+market başına tek satır.
-- Canlı maçlarda live_analysis_ttl süresi dolunca tazelenir.
CREATE TABLE IF NOT EXISTS market_analyses (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_id      BIGINT UNSIGNED NOT NULL,
    market_key    VARCHAR(64) NOT NULL,     -- 'MS' veya scraped market anahtarı (m_<hash>)
    market_label  VARCHAR(160) NOT NULL,
    is_live       TINYINT(1) NOT NULL DEFAULT 0,
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
    KEY idx_ma_match (match_id),
    CONSTRAINT fk_ma_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Kredi ile açılan market analizleri ----------
-- Aynı maçın aynı marketini tekrar görüntülemek ücretsizdir; canlıda
-- tazelik süresi dolunca yeni analiz yeni kredi ister.
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
    KEY idx_unlock_user_match (user_id, match_id),
    CONSTRAINT fk_unlock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_unlock_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AI prompt/yanıt kayıtları ----------
-- Her analiz çağrısında LLM'e gönderilen prompt ve dönen yanıt burada tutulur.
CREATE TABLE IF NOT EXISTS ai_prompt_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    analysis_type VARCHAR(20) NOT NULL,           -- 'full' (tüm maç) | 'market' (tek market)
    match_id      BIGINT UNSIGNED DEFAULT NULL,
    market_key    VARCHAR(64) DEFAULT NULL,
    analysis_id   BIGINT UNSIGNED DEFAULT NULL,    -- analyses.id veya market_analyses.id
    provider      VARCHAR(40) DEFAULT NULL,        -- gemini, openai, custom
    model_name    VARCHAR(120) DEFAULT NULL,
    system_prompt LONGTEXT,                        -- LLM'e gönderilen sistem promptu
    user_prompt   LONGTEXT,                        -- LLM'e gönderilen kullanıcı promptu
    response_text LONGTEXT,                        -- LLM'den dönen ham yanıt
    token_usage   INT UNSIGNED DEFAULT NULL,
    attempt       TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1 ilk çağrı, 2 JSON düzeltme denemesi
    web_search    TINYINT(1) NOT NULL DEFAULT 0,
    created_by    BIGINT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_apl_match (match_id, created_at),
    KEY idx_apl_type (analysis_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Ayarlar (key/value) ----------
CREATE TABLE IF NOT EXISTS settings (
    skey        VARCHAR(80) NOT NULL,
    svalue      TEXT DEFAULT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Admin kullanıcıları ----------
CREATE TABLE IF NOT EXISTS admins (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(120) DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Scraper logları ----------
CREATE TABLE IF NOT EXISTS scrape_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job         VARCHAR(64) NOT NULL,           -- fetch_fixtures, fetch_results, ...
    status      ENUM('success','partial','error') NOT NULL,
    message     TEXT DEFAULT NULL,
    items_count INT UNSIGNED DEFAULT 0,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scrape_job (job, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ============================================================
--  Varsayılan ayarlar
-- ============================================================
INSERT INTO settings (skey, svalue) VALUES
    ('ai_provider', 'gemini'),
    ('gemini_api_key', ''),
    ('gemini_model', 'gemini-1.5-flash'),
    ('openai_api_key', ''),
    ('openai_base_url', 'https://api.openai.com/v1'),
    ('openai_model', 'gpt-4o-mini'),
    ('analysis_prompt', ''),
    ('free_daily_limit', '3'),
    ('bronz_daily_limit', '15'),
    ('gumus_daily_limit', '40'),
    ('free_daily_credits', '1'),
    ('bronz_daily_credits', '20'),
    ('gumus_daily_credits', '50'),
    ('altin_daily_credits', '120'),
    ('credit_cost_market', '1'),
    ('credit_cost_live_market', '2'),
    ('live_analysis_ttl', '180'),
    ('group_min_tier_ana', '0'),
    ('group_min_tier_gol', '1'),
    ('group_min_tier_handikap', '2'),
    ('group_min_tier_ozel', '3'),
    ('ai_web_search', '1'),
    ('scraper_base_url', 'https://www.mackolik.com'),
    ('scraper_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
    ('announcement', '')
ON DUPLICATE KEY UPDATE skey = skey;

-- Varsayılan admin: kullanıcı adı "admin", şifre "admin123" (KURULUMDAN SONRA DEĞİŞTİRİN!)
-- password_hash = password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO admins (username, password_hash, name) VALUES
    ('admin', '$2y$12$g5EBryKSBRSVRPuMj223/OKfj3vRy6hHXqLxMyg3usmet5s.8f7yW', 'Yönetici')
ON DUPLICATE KEY UPDATE username = username;
