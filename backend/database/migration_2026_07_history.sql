-- ============================================================
--  MaçRadar - Migration: Analizlerim (kullanıcı analiz geçmişi)
--  Mevcut kurulumda bu tabloyu eklemek için çalıştırın.
--  (schema.sql'i baştan kuranların çalıştırmasına gerek yok.)
-- ============================================================
SET NAMES utf8mb4;

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
