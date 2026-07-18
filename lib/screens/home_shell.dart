import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/theme.dart';
import 'account_screen.dart';
import 'coupon_screen.dart';
import 'matches_screen.dart';
import 'my_analyses_screen.dart';

/// Alt navigasyonlu ana kabuk (Maçlar / Kupon / Analizlerim / Hesap).
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key});
  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  int _index = 0;

  static const _pages = [
    MatchesScreen(),
    CouponScreen(),
    MyAnalysesScreen(),
    AccountScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        bottom: false,
        top: false,
        child: IndexedStack(index: _index, children: _pages),
      ),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          color: Color(0xFF0A0F15),
          border: Border(top: BorderSide(color: AppColors.surface2)),
        ),
        child: NavigationBarTheme(
          data: NavigationBarThemeData(
            backgroundColor: Colors.transparent,
            indicatorColor: AppColors.primary.withValues(alpha: 0.16),
            labelTextStyle: WidgetStateProperty.resolveWith((states) {
              final selected = states.contains(WidgetState.selected);
              return AppText.sans(
                size: 10,
                weight: FontWeight.w700,
                color: selected ? AppColors.primary : AppColors.textSecondary,
              );
            }),
            iconTheme: WidgetStateProperty.resolveWith((states) {
              final selected = states.contains(WidgetState.selected);
              return IconThemeData(
                color: selected ? AppColors.primary : AppColors.textSecondary,
                size: 22,
              );
            }),
          ),
          child: NavigationBar(
            height: 64,
            labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
            selectedIndex: _index,
            onDestinationSelected: (i) => setState(() => _index = i),
            destinations: const [
              NavigationDestination(
                  icon: Icon(Icons.public_outlined),
                  selectedIcon: Icon(Icons.public),
                  label: 'Maçlar'),
              NavigationDestination(
                  icon: Icon(Icons.confirmation_num_outlined),
                  selectedIcon: Icon(Icons.confirmation_num),
                  label: 'Kupon'),
              NavigationDestination(
                  icon: Icon(Icons.bar_chart_outlined),
                  selectedIcon: Icon(Icons.bar_chart),
                  label: 'Analizlerim'),
              NavigationDestination(
                  icon: Icon(Icons.person_outline),
                  selectedIcon: Icon(Icons.person),
                  label: 'Hesap'),
            ],
          ),
        ),
      ),
    );
  }
}
