import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../providers/providers.dart';
import '../widgets/badges.dart';
import '../widgets/paywall_sheet.dart';
import 'app_header.dart';

/// Hesap ekranı: profil + paket durumu + paket ayrıcalıkları + tercihler.
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  /// (özellik, gereken kademe)
  static const _perks = <(String, int)>[
    ('Günlük token hakkı (her gün yenilenir)', 0),
    ('Market gruplarını token ile açma', 0),
    ('AI maç analizleri (token ile)', 0),
    ('Günde 100 token', 1),
    ('Günde 250 token', 2),
    ('Günün AI Kuponu', 2),
    ('Günde 600 token', 3),
    ('Canlı maçlarda AI tahminleri', 3),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authProvider).user;
    final tier = user?.tier ?? 0;
    final premium = tier > 0;
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
                    text: user?.planName ?? 'ÜCRETSİZ',
                    color: premium ? const Color(0xFF2A2008) : AppColors.textSecondary,
                    bg: premium ? AppColors.gold : AppColors.surface2,
                  ),
                ],
              ),
              const SizedBox(height: 16),
              _tokenCard(user?.tokensLeft ?? 0, user?.dailyTokens ?? 0),
              const SizedBox(height: 12),
              premium
                  ? _premiumCard(context, tier, user?.premiumUntil)
                  : _freeCard(context),
              const SizedBox(height: 16),
              Text('PAKET AYRICALIKLARI', style: AppText.section()),
              const SizedBox(height: 8),
              Container(
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppColors.surface2),
                ),
                child: Column(
                  children: _perks.map((p) {
                    final unlocked = tier >= p.$2;
                    return Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 10),
                      child: Row(
                        children: [
                          Icon(unlocked ? Icons.check : Icons.lock_outline,
                              size: 16,
                              color: unlocked
                                  ? AppColors.gold
                                  : AppColors.textMuted),
                          const SizedBox(width: 11),
                          Expanded(
                              child: Text(p.$1,
                                  style: AppText.sans(
                                      size: 12.5,
                                      weight: FontWeight.w600,
                                      color: unlocked
                                          ? AppColors.textPrimary
                                          : AppColors.textSecondary))),
                          if (p.$2 > 0)
                            Text(tierNames[p.$2],
                                style: AppText.sans(
                                    size: 9.5,
                                    weight: FontWeight.w800,
                                    color: unlocked
                                        ? AppColors.gold
                                        : AppColors.textMuted)),
                        ],
                      ),
                    );
                  }).toList(),
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

  Widget _premiumCard(BuildContext context, int tier, String? until) {
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
              Text('${tierNames[tier]} paket aktif',
                  style: AppText.sans(
                      size: 14.5, weight: FontWeight.w800, color: const Color(0xFFE7CE8B))),
            ],
          ),
          const SizedBox(height: 6),
          Text(until != null ? 'Yenileme: $until' : 'Paket ayrıcalıkların açık.',
              style: AppText.sans(
                  size: 11.5, weight: FontWeight.w500, color: const Color(0xFFC9B279))),
          if (tier < 3) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.gold,
                    foregroundColor: const Color(0xFF2A2008)),
                onPressed: () =>
                    showPaywall(context, highlightTier: tier + 1),
                child: Text('${tierNames[tier + 1]} paketine yükselt'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// Günlük token bakiyesi kartı (her gün sıfırlanır, devretmez).
  Widget _tokenCard(int left, int daily) {
    final ratio = daily > 0 ? (left / daily).clamp(0.0, 1.0) : 0.0;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.surface2),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.token_outlined, size: 16, color: AppColors.primary),
              const SizedBox(width: 7),
              Expanded(
                child: Text('GÜNLÜK TOKEN',
                    style: AppText.sans(
                        size: 10.5,
                        weight: FontWeight.w800,
                        color: AppColors.primary,
                        letterSpacing: 0.8)),
              ),
              Text('$left / $daily',
                  style: AppText.mono(size: 13, color: AppColors.textPrimary)),
            ],
          ),
          const SizedBox(height: 9),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: ratio,
              minHeight: 6,
              backgroundColor: AppColors.surface2,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(height: 7),
          Text(
              'Token hakkınız her gün yenilenir; kullanılmayan tokenlar ertesi güne devretmez. '
              'Market grupları ve AI analizleri token ile açılır.',
              style: AppText.sans(
                  size: 10.5,
                  weight: FontWeight.w500,
                  color: AppColors.textSecondary)),
        ],
      ),
    );
  }

  Widget _freeCard(BuildContext context) {
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
          Text(
              'Günlük tokenınız bir maçın bir market grubunu açmaya yeter. '
              'Paketlerle günlük token hakkınız artar.',
              style: AppText.sans(
                  size: 11.5, weight: FontWeight.w500, color: const Color(0xFFC9B279))),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.gold,
                  foregroundColor: const Color(0xFF2A2008)),
              onPressed: () => showPaywall(context, highlightTier: 1),
              child: const Text('Paketleri gör'),
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
