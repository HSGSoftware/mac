import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/analysis.dart';
import '../models/coupon.dart';
import '../models/match.dart';
import '../models/my_analysis.dart';
import '../models/notification.dart';
import '../models/user.dart';
import '../services/api_client.dart';
import '../services/local_notifier.dart';
import '../services/token_store.dart';

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

final apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(ref.read(tokenStoreProvider)),
);

// ---------------- Auth ----------------

class AuthState {
  final AppUser? user;
  final bool loading;
  const AuthState({this.user, this.loading = false});

  AuthState copyWith({AppUser? user, bool? loading, bool clearUser = false}) =>
      AuthState(
        user: clearUser ? null : (user ?? this.user),
        loading: loading ?? this.loading,
      );

  bool get isLoggedIn => user != null;
}

class AuthNotifier extends StateNotifier<AuthState> {
  final Ref ref;
  AuthNotifier(this.ref) : super(const AuthState());

  Future<void> bootstrap() async {
    final token = await ref.read(tokenStoreProvider).access;
    if (token == null) return;
    try {
      final data = await ref.read(apiClientProvider).get('/me');
      state = state.copyWith(user: AppUser.fromJson(data['user']));
    } catch (_) {
      await ref.read(tokenStoreProvider).clear();
    }
  }

  Future<void> login(String email, String password) async {
    state = state.copyWith(loading: true);
    try {
      final data = await ref.read(apiClientProvider).post('/auth/login', data: {
        'email': email,
        'password': password,
      });
      await _handleAuth(data);
    } finally {
      state = state.copyWith(loading: false);
    }
  }

  Future<void> register(String email, String password, String name) async {
    state = state.copyWith(loading: true);
    try {
      final data =
          await ref.read(apiClientProvider).post('/auth/register', data: {
        'email': email,
        'password': password,
        'name': name,
      });
      await _handleAuth(data);
    } finally {
      state = state.copyWith(loading: false);
    }
  }

  Future<void> _handleAuth(dynamic data) async {
    final tokens = data['tokens'];
    await ref
        .read(tokenStoreProvider)
        .save(tokens['access_token'], tokens['refresh_token']);
    state = state.copyWith(user: AppUser.fromJson(data['user']));
  }

  Future<void> refreshMe() async {
    try {
      final data = await ref.read(apiClientProvider).get('/me');
      state = state.copyWith(user: AppUser.fromJson(data['user']));
    } catch (_) {}
  }

  /// Okunmamış bildirim rozetini yerelden günceller (/me çağırmadan).
  void setUnread(int unread) {
    final u = state.user;
    if (u == null || u.unreadNotifications == unread) return;
    state = state.copyWith(
      user: AppUser(
        id: u.id,
        email: u.email,
        name: u.name,
        plan: u.plan,
        premiumUntil: u.premiumUntil,
        dailyAnalysisCount: u.dailyAnalysisCount,
        dailyCredits: u.dailyCredits,
        creditsLeft: u.creditsLeft,
        unreadNotifications: unread,
      ),
    );
  }

  Future<void> logout() async {
    await ref.read(tokenStoreProvider).clear();
    state = const AuthState();
  }
}

final authProvider =
    StateNotifierProvider<AuthNotifier, AuthState>((ref) => AuthNotifier(ref));

// ---------------- Bildirimler ----------------

class NotificationsState {
  final List<AppNotification> items;
  final int unread;
  const NotificationsState({this.items = const [], this.unread = 0});
}

/// Uygulama içi bildirimleri periyodik yoklar; yeni "analiz hazır" bildirimi
/// gelince cihaz bildirimi gösterir. Kullanıcı giriş yapınca [start], çıkınca
/// [stop] çağrılır (HomeShell yönetir).
class NotificationsNotifier extends StateNotifier<NotificationsState> {
  final Ref ref;
  Timer? _timer;
  final Set<int> _seen = {};
  bool _primed = false; // ilk yükleme: eski bildirimler için ses/titreşim yok

  NotificationsNotifier(this.ref) : super(const NotificationsState());

  void start() {
    if (_timer != null) return;
    LocalNotifier.instance.init();
    refresh();
    _timer = Timer.periodic(const Duration(seconds: 25), (_) => refresh());
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
    _seen.clear();
    _primed = false;
    state = const NotificationsState();
  }

  Future<void> refresh() async {
    try {
      final data = await ref.read(apiClientProvider).get('/me/notifications');
      final items = ((data['items'] as List?) ?? [])
          .map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      final unread = (data['unread'] as num?)?.toInt() ?? 0;

      // Yeni gelen okunmamış "hazır" bildirimleri cihazda göster
      for (final n in items) {
        if (_seen.contains(n.id)) continue;
        _seen.add(n.id);
        if (_primed && !n.isRead && n.isReady) {
          LocalNotifier.instance
              .show(n.title, n.body ?? '', matchId: n.matchId);
        }
      }
      _primed = true;
      state = NotificationsState(items: items, unread: unread);
      // Rozet sayısını /me ile de senkron tut
      ref.read(authProvider.notifier).setUnread(unread);
    } catch (_) {
      // sessiz: ağ hatası bir sonraki yoklamada düzelir
    }
  }

  Future<void> markAllRead() async {
    // İyimser güncelleme
    state = NotificationsState(
      items: state.items.map((n) => n.copyWith(isRead: true)).toList(),
      unread: 0,
    );
    ref.read(authProvider.notifier).setUnread(0);
    try {
      await ref.read(apiClientProvider).post('/me/notifications/read');
    } catch (_) {}
  }

  Future<void> markRead(int id) async {
    state = NotificationsState(
      items: state.items
          .map((n) => n.id == id ? n.copyWith(isRead: true) : n)
          .toList(),
      unread: (state.unread - 1).clamp(0, 1 << 30),
    );
    ref.read(authProvider.notifier).setUnread(state.unread);
    try {
      await ref.read(apiClientProvider).post('/me/notifications/read',
          data: {'id': id});
    } catch (_) {}
  }
}

final notificationsProvider =
    StateNotifierProvider<NotificationsNotifier, NotificationsState>(
        (ref) => NotificationsNotifier(ref));

// ---------------- Bülten (maç listesi) ----------------

final selectedDateProvider = StateProvider<DateTime>((ref) => DateTime.now());

final fixturesProvider =
    FutureProvider.autoDispose<List<LeagueGroup>>((ref) async {
  final date = ref.watch(selectedDateProvider);
  final ds =
      '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
  final data =
      await ref.read(apiClientProvider).get('/matches', query: {'date': ds});
  return ((data['leagues'] as List?) ?? [])
      .map((e) => LeagueGroup.fromJson(Map<String, dynamic>.from(e)))
      .toList();
});

// ---------------- Canlı maçlar ----------------

final liveMatchesProvider =
    FutureProvider.autoDispose<List<MatchItem>>((ref) async {
  final data = await ref.read(apiClientProvider).get('/matches/live');
  return ((data['matches'] as List?) ?? [])
      .map((e) => MatchItem.fromJson(Map<String, dynamic>.from(e)))
      .toList();
});

// ---------------- Düz (gruplanmamış) günlük maç listeleri ----------------

Future<List<MatchItem>> _flatMatchesForDate(ApiClient api, DateTime date) async {
  final ds =
      '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
  final data = await api.get('/matches', query: {'date': ds});
  final out = <MatchItem>[];
  for (final g in ((data['leagues'] as List?) ?? [])) {
    final group = LeagueGroup.fromJson(Map<String, dynamic>.from(g));
    out.addAll(group.matches);
  }
  return out;
}

final todayMatchesProvider =
    FutureProvider.autoDispose<List<MatchItem>>((ref) async {
  return _flatMatchesForDate(ref.read(apiClientProvider), DateTime.now());
});

final tomorrowMatchesProvider =
    FutureProvider.autoDispose<List<MatchItem>>((ref) async {
  return _flatMatchesForDate(
      ref.read(apiClientProvider), DateTime.now().add(const Duration(days: 1)));
});

// ---------------- Günün Kuponu ----------------

final dailyCouponProvider =
    FutureProvider.autoDispose<DailyCoupon>((ref) async {
  final data = await ref.read(apiClientProvider).get('/coupon/daily');
  return DailyCoupon.fromJson(Map<String, dynamic>.from(data));
});

// ---------------- Analizlerim ----------------

final myAnalysesProvider =
    FutureProvider.autoDispose<MyAnalysesResponse>((ref) async {
  final data = await ref.read(apiClientProvider).get('/me/analyses');
  return MyAnalysesResponse.fromJson(Map<String, dynamic>.from(data));
});

// ---------------- Maç detay ----------------

final matchDetailProvider =
    FutureProvider.autoDispose.family<MatchDetail, int>((ref, id) async {
  final data = await ref.read(apiClientProvider).get('/matches/$id');
  return MatchDetail.fromJson(Map<String, dynamic>.from(data));
});

// ---------------- Favoriler ----------------

final favoritesProvider =
    FutureProvider.autoDispose<List<MatchItem>>((ref) async {
  final data = await ref.read(apiClientProvider).get('/favorites');
  return ((data['matches'] as List?) ?? [])
      .map((e) => MatchItem.fromJson(Map<String, dynamic>.from(e)))
      .toList();
});

// ---------------- Başarı istatistiği ----------------

final successRateProvider =
    FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final data = await ref.read(apiClientProvider).get('/stats/success-rate');
  return Map<String, dynamic>.from(data);
});
