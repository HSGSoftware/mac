-- ============================================================
--  Migration: AI prompt/yanıt kayıt tablosu
--  Her analiz çağrısında LLM'e gönderilen prompt ve dönen yanıt
--  bu tabloya kaydedilir (denetim / hata ayıklama / kalite takibi).
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_prompt_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    analysis_type VARCHAR(20) NOT NULL,           -- 'full' (tüm maç) | 'market' (tek market)
    match_id      BIGINT UNSIGNED DEFAULT NULL,
    market_key    VARCHAR(64) DEFAULT NULL,        -- market analizinde ilgili market anahtarı
    analysis_id   BIGINT UNSIGNED DEFAULT NULL,    -- analyses.id veya market_analyses.id
    provider      VARCHAR(40) DEFAULT NULL,        -- gemini, openai, custom
    model_name    VARCHAR(120) DEFAULT NULL,
    system_prompt LONGTEXT,                        -- LLM'e gönderilen sistem promptu
    user_prompt   LONGTEXT,                        -- LLM'e gönderilen kullanıcı promptu
    response_text LONGTEXT,                        -- LLM'den dönen ham yanıt
    token_usage   INT UNSIGNED DEFAULT NULL,
    attempt       TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1 ilk çağrı, 2 JSON düzeltme denemesi
    web_search    TINYINT(1) NOT NULL DEFAULT 0,   -- internet araştırması açık mıydı
    created_by    BIGINT UNSIGNED DEFAULT NULL,    -- isteği yapan kullanıcı
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_apl_match (match_id, created_at),
    KEY idx_apl_type (analysis_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
