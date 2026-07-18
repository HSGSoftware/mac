import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/analysis.dart';
import '../models/match.dart';
import '../models/user.dart';
import '../services/api_client.dart';
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

  Future<void> logout() async {
    await ref.read(tokenStoreProvider).clear();
    state = const AuthState();
  }
}

final authProvider =
    StateNotifierProvider<AuthNotifier, AuthState>((ref) => AuthNotifier(ref));

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
