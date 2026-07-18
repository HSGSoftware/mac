import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../models/coupon.dart';
import '../providers/providers.dart';
import '../widgets/badges.dart';
import '../widgets/paywall_sheet.dart';
import 'app_header.dart';

/// Günün Kuponu ekranı: modelin en yüksek değer marjlı seçimleri.
class CouponScreen extends ConsumerWidget {
  const CouponScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Günün Kuponu yalnızca Altın (3) pakette
    final unlocked = (ref.watch(authProvider).user?.tier ?? 0) >= 3;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const ScreenTitle(
          title: 'Günün Kuponu',
          subtitle:
              'Model, bugünkü bültenden en yüksek değer marjlı seçimleri birleştirir.',
          showDate: true,
        ),
        const SizedBox(height: 10),
        Expanded(
          child: unlocked ? _premiumBody(context, ref) : _freeBody(context),
        ),
      ],
    );
  }

  Widget _freeBody(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
      children: [
        Container(
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
              Row(
                children: [
                  const Icon(Icons.lock_outline, color: AppColors.gold, size: 20),
                  const SizedBox(width: 9),
                  Text('Günün kuponu Altın pakette',
                      style: AppText.sans(
                          size: 13.5,
                          weight: FontWeight.w800,
                          color: const Color(0xFFE7CE8B))),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                  'Modelin seçimleri, gerekçeleri ve kombine olasılık hesabı Altın paket üyelerine açık.',
                  style: AppText.sans(
                      size: 12,
                      weight: FontWeight.w500,
                      color: const Color(0xFFC9B279))),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.gold,
                      foregroundColor: const Color(0xFF2A2008)),
                  onPressed: () => showPaywall(context, highlightTier: 3),
                  child: const Text("Altın pakete geç"),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _premiumBody(BuildContext context, WidgetRef ref) {
    final coupon = ref.watch(dailyCouponProvider);
    return coupon.when(
      loading: () =>
          const Center(child: CircularProgressIndicator(color: AppColors.primary)),
      error: (e, _) => Center(child: Text('Hata: $e')),
      data: (c) {
        if (c.locked) {
          // Sunucu tarafı kilit (paket durumu değişmiş olabilir)
          return _freeBody(context);
        }
        if (c.picks.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(28),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.receipt_long, size: 44, color: AppColors.textMuted),
                  const SizedBox(height: 10),
                  Text('Bugün için yeterli değerli analiz yok.',
                      textAlign: TextAlign.center,
                      style: AppText.sans(size: 13, color: AppColors.textSecondary)),
                  const SizedBox(height: 4),
                  Text('Birkaç maçı analiz edin; model değer bulunca kupon burada oluşur.',
                      textAlign: TextAlign.center,
                      style: AppText.sans(
                          size: 11,
                          weight: FontWeight.w500,
                          color: AppColors.textMuted)),
                  TextButton(
                    onPressed: () => ref.invalidate(dailyCouponProvider),
                    child: const Text('Yenile'),
                  ),
                ],
              ),
            ),
          );
        }
        return RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () async => ref.invalidate(dailyCouponProvider),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
            children: [
              ...c.picks.map((p) => _pickCard(context, p)),
              const SizedBox(height: 4),
              _summaryCard(c),
              const SizedBox(height: 14),
              Text('Örnek veri · bahis tavsiyesi değildir · 18+',
                  textAlign: TextAlign.center,
                  style: AppText.sans(
                      size: 10, weight: FontWeight.w500, color: AppColors.textMuted)),
            ],
          ),
        );
      },
    );
  }

  Widget _pickCard(BuildContext context, CouponPick p) {
    return GestureDetector(
      onTap: () => context.push('/match/${p.matchId}'),
      child: Container(
        margin: const EdgeInsets.only(bottom: 9),
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          gradient: AppColors.cardGradient,
          borderRadius: BorderRadius.circular(13),
          border: Border.all(color: AppColors.surface2),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                      '${p.league}${p.time != null ? ' · ${_time(p.time!)}' : ''}'
                          .toUpperCase(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppText.sans(
                          size: 9,
                          weight: FontWeight.w600,
                          color: AppColors.textSecondary,
                          letterSpacing: 0.4)),
                ),
                const SizedBox(width: 8),
                ValueBadge(edge: p.edge),
              ],
            ),
            const SizedBox(height: 7),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(p.match,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppText.sans(size: 13.5, weight: FontWeight.w700)),
                      const SizedBox(height: 3),
                      Text.rich(TextSpan(children: [
                        TextSpan(
                            text: p.pickLabel,
                            style: AppText.sans(
                                size: 11, weight: FontWeight.w800, color: AppColors.primary)),
                        TextSpan(
                            text: ' — ${p.pickName} · Model %${p.modelPct}',
                            style: AppText.sans(
                                size: 11,
                                weight: FontWeight.w500,
                                color: AppColors.textSecondary)),
                      ])),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: AppColors.oddCell,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.primary, width: 1.5),
                  ),
                  child: Text(p.odds.toStringAsFixed(2), style: AppText.mono(size: 15)),
                ),
              ],
            ),
            if (p.reason.isNotEmpty) ...[
              const SizedBox(height: 7),
              Text(p.reason,
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w500, color: AppColors.textSecondary)),
            ],
          ],
        ),
      ),
    );
  }

  Widget _summaryCard(DailyCoupon c) {
    final s = c.summary;
    Widget stat(String v, String label) => Expanded(
          child: Column(
            children: [
              Text(v, style: AppText.mono(size: 19, color: AppColors.textPrimary)),
              const SizedBox(height: 2),
              Text(label,
                  textAlign: TextAlign.center,
                  style: AppText.sans(
                      size: 9.5, weight: FontWeight.w500, color: AppColors.textSecondary)),
            ],
          ),
        );
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.accentFaint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.accentDim),
      ),
      child: Column(
        children: [
          Row(
            children: [
              stat(s.totalOdds?.toStringAsFixed(2) ?? '-', 'Toplam oran'),
              stat(s.modelPct != null ? '%${s.modelPct}' : '-', 'Model olasılığı'),
              stat(s.confidence != null ? '${s.confidence}/10' : '-', 'Ort. güven'),
            ],
          ),
          if (s.edge != null) ...[
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 11),
              child: Divider(height: 1, color: AppColors.surface2),
            ),
            Text.rich(TextSpan(children: [
              TextSpan(
                  text:
                      'Oranların ima ettiği kombine olasılık %${s.impliedPct}; model %${s.modelPct} veriyor — ',
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w500, color: AppColors.textSecondary)),
              TextSpan(
                  text: '+${s.edge} puan değer marjı.',
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w800, color: AppColors.primary)),
              TextSpan(
                  text: ' Kombine kuponlarda varyans yüksektir.',
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w500, color: AppColors.textSecondary)),
            ])),
          ],
        ],
      ),
    );
  }

  String _time(String iso) {
    final dt = DateTime.tryParse(iso.replaceFirst(' ', 'T'));
    if (dt == null) return '';
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }
}
