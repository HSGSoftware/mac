import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../providers/providers.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authProvider).user;
    if (user == null) {
      return const Center(child: CircularProgressIndicator());
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                CircleAvatar(
                  radius: 28,
                  backgroundColor: AppColors.primary,
                  child: Text(
                    (user.name?.isNotEmpty == true
                            ? user.name![0]
                            : user.email[0])
                        .toUpperCase(),
                    style: const TextStyle(
                        color: AppColors.bg,
                        fontWeight: FontWeight.bold,
                        fontSize: 22),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(user.name ?? 'Kullanıcı',
                          style: const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.bold)),
                      Text(user.email,
                          style: const TextStyle(
                              color: AppColors.textSecondary, fontSize: 13)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                        user.isPremium
                            ? Icons.workspace_premium
                            : Icons.person,
                        color: user.isPremium
                            ? AppColors.gold
                            : AppColors.textSecondary),
                    const SizedBox(width: 8),
                    Text(user.isPremium ? 'Premium Üye' : 'Ücretsiz Üye',
                        style: const TextStyle(
                            fontSize: 16, fontWeight: FontWeight.bold)),
                  ],
                ),
                const SizedBox(height: 8),
                if (user.isPremium)
                  Text('Premium bitiş: ${user.premiumUntil ?? '-'}',
                      style: const TextStyle(color: AppColors.textSecondary))
                else ...[
                  Text('Bugün kullanılan analiz: ${user.dailyAnalysisCount}',
                      style: const TextStyle(color: AppColors.textSecondary)),
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.gold),
                      onPressed: () => _premiumInfo(context),
                      icon: const Icon(Icons.workspace_premium),
                      label: const Text('Premium\'a Geç'),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        Card(
          child: Column(
            children: [
              ListTile(
                leading: const Icon(Icons.info_outline),
                title: const Text('Uygulama Hakkında'),
                onTap: () => showAboutDialog(
                  context: context,
                  applicationName: 'MaçRadar',
                  applicationVersion: '1.0.0',
                  children: const [
                    Text(
                        'AI destekli iddaa maç ve oran analiz uygulaması.\n\nAnalizler yatırım tavsiyesi değildir; 18 yaş sınırı geçerlidir.'),
                  ],
                ),
              ),
              const Divider(height: 1, color: AppColors.surface2),
              ListTile(
                leading: const Icon(Icons.logout, color: AppColors.danger),
                title: const Text('Çıkış Yap',
                    style: TextStyle(color: AppColors.danger)),
                onTap: () async {
                  await ref.read(authProvider.notifier).logout();
                  if (context.mounted) context.go('/auth');
                },
              ),
            ],
          ),
        ),
        const SizedBox(height: 24),
        const Text(
          'Analizler yatırım tavsiyesi değildir. 18+',
          textAlign: TextAlign.center,
          style: TextStyle(color: AppColors.textSecondary, fontSize: 11),
        ),
      ],
    );
  }

  void _premiumInfo(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text(
            'Premium satın alma yakında! (Mağaza içi satın alma entegrasyonu eklenecek.)'),
      ),
    );
  }
}
