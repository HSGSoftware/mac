<?php

namespace MacRadar\Core;

use PDO;
use PDOException;

/**
 * PDO singleton veritabanı sarmalayıcı.
 */
class Database
{
    private static ?PDO $pdo = null;

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
        }
        return self::$pdo;
    }

    /** Tek satır getir */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Çok satır getir */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** INSERT/UPDATE/DELETE çalıştır, etkilenen satır sayısını döndür */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** INSERT çalıştır, son eklenen id'yi döndür */
    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }
}
