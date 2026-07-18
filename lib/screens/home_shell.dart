import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/theme.dart';
import 'favorites_screen.dart';
import 'fixtures_screen.dart';
import 'profile_screen.dart';
import 'stats_screen.dart';

/// Alt navigasyonlu ana kabuk.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key});
  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  int _index = 0;

  static const _titles = ['Bülten', 'Favoriler', 'İstatistik', 'Profil'];
  static const _pages = [
    FixturesScreen(),
    FavoritesScreen(),
    StatsScreen(),
    ProfileScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            const Icon(Icons.insights, color: AppColors.primary),
            const SizedBox(width: 8),
            Text(_titles[_index]),
          ],
        ),
      ),
      body: IndexedStack(index: _index, children: _pages),
      bottomNavigationBar: NavigationBar(
        backgroundColor: AppColors.surface,
        indicatorColor: AppColors.primary.withValues(alpha: 0.2),
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: const [
          NavigationDestination(
              icon: Icon(Icons.sports_soccer), label: 'Bülten'),
          NavigationDestination(icon: Icon(Icons.star), label: 'Favoriler'),
          NavigationDestination(
              icon: Icon(Icons.bar_chart), label: 'İstatistik'),
          NavigationDestination(icon: Icon(Icons.person), label: 'Profil'),
        ],
      ),
    );
  }
}
