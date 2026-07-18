import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../providers/providers.dart';
import '../screens/auth_screen.dart';
import '../screens/home_shell.dart';
import '../screens/match_detail_screen.dart';
import '../screens/splash_screen.dart';

final routerProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    initialLocation: '/splash',
    routes: [
      GoRoute(path: '/splash', builder: (c, s) => const SplashScreen()),
      GoRoute(path: '/auth', builder: (c, s) => const AuthScreen()),
      GoRoute(path: '/', builder: (c, s) => const HomeShell()),
      GoRoute(
        path: '/match/:id',
        builder: (c, s) =>
            MatchDetailScreen(matchId: int.parse(s.pathParameters['id']!)),
      ),
    ],
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      final loc = state.matchedLocation;
      if (loc == '/splash') return null;
      final loggedIn = auth.isLoggedIn;
      final goingToAuth = loc == '/auth';
      if (!loggedIn && !goingToAuth) return '/auth';
      if (loggedIn && goingToAuth) return '/';
      return null;
    },
  );
});
