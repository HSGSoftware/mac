import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../providers/providers.dart';
import '../widgets/badges.dart';
import '../widgets/paywall_sheet.dart';
import 'app_header.dart';

/// Hesap ekranı: profil + plan durumu + premium ayrıcalıkları + tercihler.
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  static const _perks = [
    'Model kazanma olasılıkları',
    'DEĞER sinyalleri ve bildirimleri',
    'Analiz gerekçeleri + güven puanı',
    'Canlı maç istatistikleri',
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authProvider).user;
    final premium = user?.isPremium ?? false;
    final initials = _initials(user?.name, user?.email);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const ScreenTitle(title: 'Hesap'),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
            children: [
              Row(
                children: [
                  Container(
                    width: 54,
                    height: 54,
                    decoration: BoxDecoration(
                      gradient: premium ? AppColors.goldGradient : null,
                      color: premium ? null : AppColors.surface2,
                      shape: BoxShape.circle,
                    ),
                    alignment: Alignment.center,
                    child: Text(initials,
                        style: AppText.sans(
                            size: 18,
                            weight: FontWeight.w800,
                            color: premium ? const Color(0xFF2A2008) : AppColors.textPrimary)),
                  ),
                  const SizedBox(width: 13),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(user?.name ?? 'Kullanıcı',
                            style: AppText.sans(size: 16.5, weight: FontWeight.w800)),
                        const SizedBox(height: 2),
                        Text(user?.email ?? '',
                            style: AppText.sans(
                                size: 11,
                                weight: FontWeight.w500,
                                color: AppColors.textSecondary)),
                      ],
                    ),
                  ),
                  Pill(
                    text: premium ? 'PREMIUM' : 'ÜCRETSİZ',
                    color: premium ? const Color(0xFF2A2008) : AppColors.textSecondary,
                    bg: premium ? AppColors.gold : AppColors.surface2,
                  ),
                ],
              ),
              const SizedBox(height: 16),
              premium ? _premiumCard(user?.premiumUntil) : _freeCard(context, user?.dailyAnalysisCount ?? 0),
              const SizedBox(height: 16),
              Text('PREMIUM AYRICALIKLARI', style: AppText.section()),
              const SizedBox(height: 8),
              Container(
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppColors.surface2),
                ),
                child: Column(
                  children: _perks
                      .map((p) => Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            child: Row(
                              children: [
                                Icon(Icons.check,
                                    size: 16,
                                    color: premium ? AppColors.gold : AppColors.textMuted),
                                const SizedBox(width: 11),
                                Expanded(
                                    child: Text(p,
                                        style: AppText.sans(
                                            size: 12.5,
                                            weight: FontWeight.w600,
                                            color: premium
                                                ? AppColors.textPrimary
                                                : AppColors.textSecondary))),
                                if (!premium)
                                  const Icon(Icons.lock_outline,
                                      size: 14, color: AppColors.textMuted),
                              ],
                            ),
                          ))
                      .toList(),
                ),
              ),
              const SizedBox(height: 16),
              Text('TERCİHLER', style: AppText.section()),
              const SizedBox(height: 8),
              Container(
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppColors.surface2),
                ),
                child: Column(
                  children: [
                    _prefRow('Bildirimler', 'DEĞER sinyalleri açık'),
                    const Divider(height: 1, color: AppColors.surface2),
                    _prefRow('Oran formatı', 'Ondalık (2.10)'),
                    const Divider(height: 1, color: AppColors.surface2),
                    _actionRow(
                      icon: Icons.info_outline,
                      label: 'Uygulama Hakkında',
                      onTap: () => showAboutDialog(
                        context: context,
                        applicationName: 'Maç Analiz',
                        applicationVersion: '1.0.0',
                        children: const [
                          Text(
                              'AI destekli iddaa maç ve oran analiz uygulaması.\n\nAnalizler yatırım tavsiyesi değildir; 18 yaş sınırı geçerlidir.'),
                        ],
                      ),
                    ),
                    const Divider(height: 1, color: AppColors.surface2),
                    _actionRow(
                      icon: Icons.logout,
                      label: 'Çıkış Yap',
                      danger: true,
                      onTap: () async {
                        await ref.read(authProvider.notifier).logout();
                        if (context.mounted) context.go('/auth');
                      },
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 18),
              Text('Analiz platformu · bahis tavsiyesi değildir · 18+',
                  textAlign: TextAlign.center,
                  style: AppText.sans(
                      size: 10, weight: FontWeight.w500, color: AppColors.textMuted)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _premiumCard(String? until) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF2A2210), Color(0xFF12181E)],
        ),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.gold.withValues(alpha: 0.6)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.workspace_premium, color: AppColors.gold, size: 20),
              const SizedBox(width: 9),
              Text('Premium aktif',
                  style: AppText.sans(
                      size: 14.5, weight: FontWeight.w800, color: const Color(0xFFE7CE8B))),
            ],
          ),
          const SizedBox(height: 6),
          Text(until != null ? 'Yenileme: $until' : 'Tüm model sinyalleri açık.',
              style: AppText.sans(
                  size: 11.5, weight: FontWeight.w500, color: const Color(0xFFC9B279))),
        ],
      ),
    );
  }

  Widget _freeCard(BuildContext context, int used) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF241C0C), Color(0xFF12181E)],
        ),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.gold.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Ücretsiz plandasınız',
              style: AppText.sans(
                  size: 14.5, weight: FontWeight.w800, color: const Color(0xFFE7CE8B))),
          const SizedBox(height: 5),
          Text('Model olasılıkları, DEĞER sinyalleri ve gerekçeler kilitli.',
              style: AppText.sans(
                  size: 11.5, weight: FontWeight.w500, color: const Color(0xFFC9B279))),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.gold,
                  foregroundColor: const Color(0xFF2A2008)),
              onPressed: () => showPaywall(context),
              child: const Text("Premium'a geç"),
            ),
          ),
        ],
      ),
    );
  }

  Widget _prefRow(String title, String value) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: [
            Expanded(
                child: Text(title,
                    style: AppText.sans(size: 12.5, weight: FontWeight.w600))),
            Text(value,
                style: AppText.sans(
                    size: 11, weight: FontWeight.w500, color: AppColors.textSecondary)),
          ],
        ),
      );

  Widget _actionRow({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool danger = false,
  }) {
    final color = danger ? AppColors.danger : AppColors.textPrimary;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        child: Row(
          children: [
            Icon(icon, size: 18, color: danger ? AppColors.danger : AppColors.textSecondary),
            const SizedBox(width: 11),
            Text(label, style: AppText.sans(size: 12.5, weight: FontWeight.w600, color: color)),
          ],
        ),
      ),
    );
  }

  String _initials(String? name, String? email) {
    final src = (name != null && name.trim().isNotEmpty) ? name.trim() : (email ?? '?');
    final parts = src.split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return src.substring(0, src.length >= 2 ? 2 : 1).toUpperCase();
  }
}
