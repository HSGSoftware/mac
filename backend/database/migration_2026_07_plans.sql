-- ============================================================
--  MaçRadar - Migration: 3 paketli premium (bronz/gumus/altin)
--  users.plan kolonunu genişletir ve eski 'premium'ları Altın yapar.
--  migrate.php bu dosyayı otomatik uygular; tekrar çalıştırılabilir.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE users MODIFY plan VARCHAR(20) NOT NULL DEFAULT 'free';

UPDATE users SET plan = 'altin' WHERE plan = 'premium';

INSERT INTO settings (skey, svalue) VALUES
    ('bronz_daily_limit', '15'),
    ('gumus_daily_limit', '40')
ON DUPLICATE KEY UPDATE skey = skey;
