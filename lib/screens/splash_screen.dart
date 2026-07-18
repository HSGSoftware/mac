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
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 74,
              height: 74,
              decoration: BoxDecoration(
                gradient: AppColors.primaryGradient,
                borderRadius: BorderRadius.circular(20),
              ),
              alignment: Alignment.center,
              child: Text('MA',
                  style: AppText.sans(
                      size: 30,
                      weight: FontWeight.w800,
                      color: const Color(0xFF0A1410),
                      letterSpacing: -1)),
            ),
            const SizedBox(height: 18),
            Text('Maç Analiz',
                style: AppText.sans(size: 28, weight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text('AI destekli iddaa analiz platformu',
                style: AppText.sans(
                    size: 13,
                    weight: FontWeight.w500,
                    color: AppColors.textSecondary)),
            const SizedBox(height: 30),
            const CircularProgressIndicator(color: AppColors.primary),
          ],
        ),
      ),
    );
  }
}
