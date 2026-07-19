<?php

namespace MacRadar\Core;

use PDO;
use PDOException;

/**
 * PDO singleton veritabanı sarmalayıcı.
 * Uzun süren isteklerde (ör. AI çağrısı) MySQL boştaki bağlantıyı kapatabilir
 * ("server has gone away"); bu durumda otomatik yeniden bağlanıp sorgu tekrarlanır.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static bool $maintenanceDone = false;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Config::get('db.host', 'localhost');
            $name = Config::get('db.name');
            $charset = Config::get('db.charset', 'utf8mb4');
            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
            try {
                self::$pdo = new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'error' => 'db_connection_failed',
                    'message' => Config::get('app.debug') ? $e->getMessage() : 'Veritabanı bağlantısı kurulamadı.',
                ]);
                exit;
            }
            // Oturum saat dilimini İstanbul'a sabitle. Türkiye yıl boyu UTC+3'tür
            // (2016'dan beri yaz saati yok). Böylece NOW()/CURDATE() ile İstanbul
            // saatinde saklanan start_time değerleri tutarlı karşılaştırılır.
            try {
                self::$pdo->exec("SET time_zone = '+03:00'");
            } catch (\Throwable $e) {
                // desteklenmiyorsa sessizce geç
            }
            self::runMaintenance();
        }
        return self::$pdo;
    }

    /**
     * Hafif bakım: bitmesine rağmen 'live' takılı kalan maçları kapatır.
     * İstek başına bir kez çalışır. Maç ~2.5 saat sonra kesinlikle bitmiştir
     * (90 dk + devre arası + uzatmalar + geç başlama payı).
     */
    private static function runMaintenance(): void
    {
        if (self::$maintenanceDone) {
            return;
        }
        self::$maintenanceDone = true;
        try {
            self::$pdo->exec(
                "UPDATE matches SET status='finished'
                 WHERE status='live' AND start_time < (NOW() - INTERVAL 150 MINUTE)"
            );
        } catch (\Throwable $e) {
            // bakım hatası uygulamayı etkilemesin
        }
    }

    /** Bağlantıyı sıfırlar (yeniden bağlanma için). */
    public static function reconnect(): PDO
    {
        self::$pdo = null;
        return self::pdo();
    }

    private static function isGoneAway(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if ($code === 2006 || $code === 2013) {
            return true;
        }
        $msg = $e->getMessage();
        return stripos($msg, 'server has gone away') !== false
            || stripos($msg, 'Lost connection') !== false
            || stripos($msg, 'MySQL server') !== false;
    }

    /**
     * Sorguyu çalıştırır; "gone away" durumunda bir kez yeniden bağlanıp tekrar dener.
     */
    private static function run(callable $fn)
    {
        try {
            return $fn(self::pdo());
        } catch (PDOException $e) {
            if (self::isGoneAway($e)) {
                return $fn(self::reconnect());
            }
            throw $e;
        }
    }

    /** Tek satır getir */
    public static function fetch(string $sql, array $params = []): ?array
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        });
    }

    /** Çok satır getir */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    /** INSERT/UPDATE/DELETE çalıştır, etkilenen satır sayısını döndür */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        });
    }

    /** INSERT çalıştır, son eklenen id'yi döndür */
    public static function insert(string $sql, array $params = []): int
    {
        return self::run(function (PDO $pdo) use ($sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $pdo->lastInsertId();
        });
    }
}
