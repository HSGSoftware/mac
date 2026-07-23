/// Uygulama içi bildirim. Analiz arka planda hazır olunca backend bir kayıt
/// düşer; uygulama bunu bildirim merkezinde ve cihaz bildirimi olarak gösterir.
class AppNotification {
  final int id;
  final String type; // analysis_ready / analysis_failed / info
  final String title;
  final String? body;
  final int? matchId;
  final bool isRead;
  final String? createdAt;

  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    this.body,
    this.matchId,
    this.isRead = false,
    this.createdAt,
  });

  bool get isReady => type == 'analysis_ready';

  AppNotification copyWith({bool? isRead}) => AppNotification(
        id: id,
        type: type,
        title: title,
        body: body,
        matchId: matchId,
        isRead: isRead ?? this.isRead,
        createdAt: createdAt,
      );

  factory AppNotification.fromJson(Map<String, dynamic> j) => AppNotification(
        id: j['id'] as int? ?? 0,
        type: j['type']?.toString() ?? 'info',
        title: j['title']?.toString() ?? '',
        body: j['body'] as String?,
        matchId: (j['match_id'] as num?)?.toInt(),
        isRead: j['is_read'] as bool? ?? false,
        createdAt: j['created_at'] as String?,
      );
}
