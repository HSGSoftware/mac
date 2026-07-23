import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Cihaz (yerel) bildirimleri için ince bir sarmalayıcı.
///
/// Firebase/FCM kullanılmaz; uygulama açıkken periyodik yoklamayla gelen
/// "analiz hazır" bildirimleri bu servisle telefonun bildirim çekmecesinde
/// gösterilir. Uygulama tamamen kapalıyken bildirim gelmez (bu bilinçli bir
/// tercihtir — push altyapısı ileride eklenebilir).
class LocalNotifier {
  LocalNotifier._();
  static final LocalNotifier instance = LocalNotifier._();

  final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  bool _ready = false;
  int _seq = 0;

  /// Bir bildirime dokunulduğunda tetiklenir (payload = match_id).
  void Function(int matchId)? onTapMatch;

  Future<void> init() async {
    if (_ready) return;
    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    const settings = InitializationSettings(android: android);
    try {
      await _plugin.initialize(
        settings,
        onDidReceiveNotificationResponse: (resp) {
          final payload = resp.payload;
          if (payload != null && payload.isNotEmpty) {
            final id = int.tryParse(payload);
            if (id != null) onTapMatch?.call(id);
          }
        },
      );
      // Android 13+ bildirim izni
      final android13 = _plugin.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      await android13?.requestNotificationsPermission();
      _ready = true;
    } catch (e) {
      // Bildirim altyapısı kurulamazsa uygulama akışını bozma
      debugPrint('LocalNotifier init hatası: $e');
    }
  }

  Future<void> show(String title, String body, {int? matchId}) async {
    if (!_ready) await init();
    if (!_ready) return;
    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'analysis_ready',
        'Analiz Bildirimleri',
        channelDescription: 'AI analizi hazır olduğunda gönderilen bildirimler',
        importance: Importance.high,
        priority: Priority.high,
      ),
    );
    try {
      await _plugin.show(
        _seq++,
        title,
        body,
        details,
        payload: matchId?.toString(),
      );
    } catch (e) {
      debugPrint('LocalNotifier show hatası: $e');
    }
  }
}
