import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../providers/providers.dart';

class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});
  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _boot();
  }

  Future<void> _boot() async {
    await ref.read(authProvider.notifier).bootstrap();
    await Future.delayed(const Duration(milliseconds: 600));
    if (!mounted) return;
    final loggedIn = ref.read(authProvider).isLoggedIn;
    context.go(loggedIn ? '/' : '/auth');
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.insights, size: 72, color: AppColors.primary),
            SizedBox(height: 16),
            Text('MaçRadar',
                style: TextStyle(
                    fontSize: 32,
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary)),
            SizedBox(height: 8),
            Text('İddaa Analiz Asistanın',
                style: TextStyle(color: AppColors.textSecondary)),
            SizedBox(height: 32),
            CircularProgressIndicator(color: AppColors.primary),
          ],
        ),
      ),
    );
  }
}
