<?php

namespace MacRadar\Services;

use MacRadar\Core\Database;

/**
 * Uygulama içi bildirimler.
 *
 * AI analizi arka planda üretildiği için kullanıcı ekranda beklemez; analiz
 * hazır olduğunda buraya bir kayıt düşer ve uygulama bunu bildirim olarak
 * gösterir (zil rozeti + cihaz bildirimi).
 *
 * Tablo yoksa (migration uygulanmadıysa) hiçbir metot hata fırlatmaz —
 * bildirim kaybı analiz akışını bozmamalıdır.
 */
class NotificationService
{
    public const TYPE_READY = 'analysis_ready';
    public const TYPE_FAILED = 'analysis_failed';
    public const TYPE_INFO = 'info';

    /** Yeni bildirim ekler. Aynı maç+tip için okunmamış kayıt varsa tazeler. */
    public static function push(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $matchId = null,
        array $data = []
    ): void {
        if ($userId <= 0) {
            return;
        }
        try {
            // Aynı maç için okunmamış aynı tip bildirim varsa çoğaltma
            if ($matchId !== null) {
                $dup = Database::fetch(
                    'SELECT id FROM notifications
                     WHERE user_id = ? AND match_id = ? AND type = ? AND is_read = 0
                     LIMIT 1',
                    [$userId, $matchId, $type]
                );
                if ($dup) {
                    Database::execute(
                        'UPDATE notifications SET title = ?, body = ?, data = ?, created_at = NOW() WHERE id = ?',
                        [
                            mb_substr($title, 0, 160),
                            $body !== null ? mb_substr($body, 0, 500) : null,
                            $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                            $dup['id'],
                        ]
                    );
                    return;
                }
            }
            Database::insert(
                'INSERT INTO notifications (user_id, type, title, body, match_id, data)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $type,
                    mb_substr($title, 0, 160),
                    $body !== null ? mb_substr($body, 0, 500) : null,
                    $matchId,
                    $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                ]
            );
        } catch (\Throwable $e) {
            // Bildirim yazılamazsa analiz akışı etkilenmez
        }
    }

    /** Kullanıcının bildirimleri (yeniden eskiye). */
    public static function listFor(int $userId, int $limit = 50): array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT n.id, n.type, n.title, n.body, n.match_id, n.data, n.is_read, n.created_at
                 FROM notifications n
                 WHERE n.user_id = ?
                 ORDER BY n.id DESC
                 LIMIT ' . max(1, min(200, $limit)),
                [$userId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'title' => $r['title'],
                'body' => $r['body'],
                'match_id' => $r['match_id'] !== null ? (int) $r['match_id'] : null,
                'data' => $r['data'] ? json_decode($r['data'], true) : null,
                'is_read' => (bool) $r['is_read'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);
    }

    /** Okunmamış bildirim sayısı. */
    public static function unreadCount(int $userId): int
    {
        try {
            return (int) (Database::fetch(
                'SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0',
                [$userId]
            )['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Bildirimi (veya $id null ise tümünü) okundu işaretler. */
    public static function markRead(int $userId, ?int $id = null): void
    {
        try {
            if ($id === null) {
                Database::execute(
                    'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
                    [$userId]
                );
            } else {
                Database::execute(
                    'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?',
                    [$userId, $id]
                );
            }
        } catch (\Throwable $e) {
            // sessiz
        }
    }

    /**
     * "Analiziniz hazır" bildirimi — analiz sonucundan kısa bir özet çıkarır.
     *
     * @param array $summary ['tavsiye' => 'MS1', 'label' => 'Maç Sonucu', 'olasilik' => 62]
     */
    public static function analysisReady(int $userId, int $matchId, string $matchName, array $summary = []): void
    {
        $body = 'Tüm marketler için yapay zeka analizi tamamlandı.';
        if (!empty($summary['tavsiye'])) {
            $pct = isset($summary['olasilik']) ? ' (%' . (int) $summary['olasilik'] . ')' : '';
            $body = 'Öne çıkan tahmin: ' . $summary['tavsiye'] . $pct . '. Detaylar için dokunun.';
        }
        self::push(
            $userId,
            self::TYPE_READY,
            $matchName . ' analiziniz hazır',
            $body,
            $matchId,
            $summary
        );
    }
}
