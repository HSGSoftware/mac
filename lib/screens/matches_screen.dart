import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../models/match.dart';
import '../providers/providers.dart';
import '../widgets/match_card.dart';
import '../widgets/paywall_sheet.dart';
import 'app_header.dart';

/// Maçlar ekranı: Canlı / Bugün / Yarın sekmeleri + maç listesi.
class MatchesScreen extends ConsumerStatefulWidget {
  const MatchesScreen({super.key});
  @override
  ConsumerState<MatchesScreen> createState() => _MatchesScreenState();
}

class _MatchesScreenState extends ConsumerState<MatchesScreen> {
  int _tab = 1; // 0=Canlı, 1=Bugün, 2=Yarın

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authProvider).user;
    // Bülten DEĞER sinyalleri ve model favorisi Gümüş (2) ve üzeri paketlerde
    final premium = (user?.tier ?? 0) >= 2;
    final live = ref.watch(liveMatchesProvider);
    final today = ref.watch(todayMatchesProvider);
    final tomorrow = ref.watch(tomorrowMatchesProvider);

    int? countOf(AsyncValue<List<MatchItem>> v) => v.asData?.value.length;

    return Column(
      children: [
        AppHeader(premium: (user?.tier ?? 0) > 0),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
          child: Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.surface2),
            ),
            child: Row(
              children: [
                _tabBtn('Canlı', 0, countOf(live), live: true),
                _tabBtn('Bugün', 1, countOf(today)),
                _tabBtn('Yarın', 2, countOf(tomorrow)),
              ],
            ),
          ),
        ),
        if (premium)
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 0, 18, 6),
            child: Text.rich(
              TextSpan(children: [
                TextSpan(
                    text: 'DEĞER',
                    style: AppText.sans(
                        size: 10, weight: FontWeight.w800, color: AppColors.primary)),
                TextSpan(
                    text:
                        ' = oran, model olasılığının altında fiyatlanmış · çerçeveli oran = modelin favorisi',
                    style: AppText.sans(
                        size: 10,
                        weight: FontWeight.w500,
                        color: AppColors.textSecondary)),
              ]),
            ),
          ),
        Expanded(
          child: _tab == 0
              ? _body(live, premium, live: true)
              : _tab == 1
                  ? _body(today, premium)
                  : _body(tomorrow, premium),
        ),
      ],
    );
  }

  Widget _tabBtn(String label, int value, int? count, {bool live = false}) {
    final selected = _tab == value;
    return Expanded(
      child: GestureDetector(
        onTap: () {
          setState(() => _tab = value);
          if (value == 0) ref.invalidate(liveMatchesProvider);
        },
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.symmetric(vertical: 9),
          decoration: BoxDecoration(
            color: selected ? const Color(0xFF2A323B) : Colors.transparent,
            borderRadius: BorderRadius.circular(9),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (live) ...[
                Container(
                  width: 6,
                  height: 6,
                  decoration: const BoxDecoration(
                      color: AppColors.danger, shape: BoxShape.circle),
                ),
                const SizedBox(width: 6),
              ],
              Text(label,
                  style: AppText.sans(
                    size: 12.5,
                    weight: FontWeight.w700,
                    color: selected ? AppColors.textPrimary : AppColors.textSecondary,
                  )),
              if (count != null) ...[
                const SizedBox(width: 6),
                Text('$count',
                    style: AppText.mono(
                      size: 10,
                      color: selected ? AppColors.primary : AppColors.textMuted,
                    )),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _body(AsyncValue<List<MatchItem>> av, bool premium, {bool live = false}) {
    return av.when(
      loading: () => Center(
          child: CircularProgressIndicator(
              color: live ? AppColors.danger : AppColors.primary)),
      error: (e, _) => _error(e.toString(), live),
      data: (matches) {
        if (matches.isEmpty) {
          return _empty(live
              ? 'Şu an canlı maç yok.'
              : 'Bu güne ait maç bulunamadı.');
        }
        return RefreshIndicator(
          color: live ? AppColors.danger : AppColors.primary,
          onRefresh: () async => ref.invalidate(
              live ? liveMatchesProvider : (_tab == 1 ? todayMatchesProvider : tomorrowMatchesProvider)),
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 2, 16, 16),
            itemCount: matches.length + (premium ? 0 : 1),
            itemBuilder: (c, i) {
              if (!premium && i == 0) return _lockBanner();
              final m = matches[premium ? i : i - 1];
              return MatchCard(
                match: m,
                premium: premium,
                onTap: () => context.push('/match/${m.id}'),
              );
            },
          ),
        );
      },
    );
  }

  Widget _lockBanner() => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: GestureDetector(
          onTap: () => showPaywall(context, highlightTier: 2),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                begin: Alignment.centerLeft,
                end: Alignment.centerRight,
                colors: [Color(0xFF241C0C), Color(0xFF141A20)],
              ),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.gold.withValues(alpha: 0.5)),
            ),
            child: Row(
              children: [
                const Icon(Icons.lock_outline, color: AppColors.gold, size: 18),
                const SizedBox(width: 10),
                Expanded(
                  child: Text.rich(
                    TextSpan(children: [
                      TextSpan(
                          text: 'Bülten değer sinyalleri Gümüş pakette. ',
                          style: AppText.sans(
                              size: 12, weight: FontWeight.w800, color: const Color(0xFFE3C583))),
                      TextSpan(
                          text: 'Değerli oranları (DEĞER) ve modelin favorisini listede işaretleriz.',
                          style: AppText.sans(
                              size: 12,
                              weight: FontWeight.w500,
                              color: const Color(0xFFCDB681))),
                    ]),
                  ),
                ),
                Text('Aç ›', style: AppText.sans(size: 11, weight: FontWeight.w800, color: AppColors.gold)),
              ],
            ),
          ),
        ),
      );

  Widget _empty(String msg) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.sports_soccer, size: 42, color: AppColors.textMuted),
            const SizedBox(height: 10),
            Text(msg, style: AppText.sans(size: 13, color: AppColors.textSecondary)),
            TextButton(
              onPressed: () {
                ref.invalidate(liveMatchesProvider);
                ref.invalidate(todayMatchesProvider);
                ref.invalidate(tomorrowMatchesProvider);
              },
              child: const Text('Yenile'),
            ),
          ],
        ),
      );

  Widget _error(String msg, bool live) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.wifi_off, color: AppColors.danger, size: 40),
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Text(msg, textAlign: TextAlign.center),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => ref.invalidate(live
                  ? liveMatchesProvider
                  : (_tab == 1 ? todayMatchesProvider : tomorrowMatchesProvider)),
              child: const Text('Tekrar dene'),
            ),
          ],
        ),
      );
}
