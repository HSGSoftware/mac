import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../models/my_analysis.dart';
import '../providers/providers.dart';
import 'app_header.dart';

/// "Analizlerim": kullanıcının incelediği maçların AI sonuçları + isabet takibi.
class MyAnalysesScreen extends ConsumerWidget {
  const MyAnalysesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(myAnalysesProvider);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const ScreenTitle(
          title: 'Analizlerim',
          subtitle: 'İncelediğiniz maçların AI analizleri burada birikir.',
        ),
        const SizedBox(height: 10),
        Expanded(
          child: data.when(
            loading: () => const Center(
                child: CircularProgressIndicator(color: AppColors.primary)),
            error: (e, _) => Center(child: Text('Hata: $e')),
            data: (res) {
              if (res.items.isEmpty) {
                return _empty(ref);
              }
              return RefreshIndicator(
                color: AppColors.primary,
                onRefresh: () async => ref.invalidate(myAnalysesProvider),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
                  children: [
                    _statsRow(res),
                    const SizedBox(height: 12),
                    ...res.items.map((a) => _row(context, a)),
                    const SizedBox(height: 12),
                    Text('Maç detayına her girişiniz otomatik olarak buraya kaydedilir.',
                        textAlign: TextAlign.center,
                        style: AppText.sans(
                            size: 10,
                            weight: FontWeight.w500,
                            color: AppColors.textMuted)),
                  ],
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _statsRow(MyAnalysesResponse r) {
    Widget cell(String v, String label, {Color? color}) => Expanded(
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 11, horizontal: 6),
            margin: const EdgeInsets.symmetric(horizontal: 3),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.surface2),
            ),
            child: Column(
              children: [
                Text(v, style: AppText.mono(size: 17, color: color ?? AppColors.textPrimary)),
                const SizedBox(height: 2),
                Text(label,
                    textAlign: TextAlign.center,
                    style: AppText.sans(
                        size: 9, weight: FontWeight.w500, color: AppColors.textSecondary)),
              ],
            ),
          ),
        );
    return Row(
      children: [
        cell('${r.count}', 'Analiz'),
        cell(r.hitPct != null ? '%${r.hitPct}' : '-', 'İsabet', color: AppColors.primary),
        cell(r.avgOdds != null ? r.avgOdds!.toStringAsFixed(2) : '-', 'Ort. oran'),
      ],
    );
  }

  Widget _row(BuildContext context, MyAnalysisItem a) {
    final chip = _statusChip(a.status);
    return GestureDetector(
      onTap: () => context.push('/match/${a.matchId}'),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(13),
          border: Border.all(color: AppColors.surface2),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text(_date(a.date),
                          style: AppText.mono(
                              size: 9.5, weight: FontWeight.w500, color: AppColors.textMuted)),
                      const SizedBox(width: 7),
                      Flexible(
                        child: Text((a.league ?? '').toUpperCase(),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppText.sans(
                                size: 9,
                                weight: FontWeight.w600,
                                color: AppColors.textSecondary,
                                letterSpacing: 0.4)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(a.match,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppText.sans(size: 13, weight: FontWeight.w700)),
                  const SizedBox(height: 3),
                  if (a.hasAnalysis && a.pick != null)
                    Text.rich(TextSpan(children: [
                      TextSpan(
                          text: 'Model: ',
                          style: AppText.sans(
                              size: 10.5,
                              weight: FontWeight.w500,
                              color: AppColors.textSecondary)),
                      TextSpan(
                          text: '${msShort[a.pick] ?? a.pick}'
                              '${a.odds != null ? ' @${a.odds!.toStringAsFixed(2)}' : ''}'
                              '${a.modelPct != null ? ' · %${a.modelPct}' : ''}',
                          style: AppText.sans(size: 10.5, weight: FontWeight.w700)),
                    ]))
                  else
                    Text('Henüz analiz yapılmadı',
                        style: AppText.sans(
                            size: 10.5,
                            weight: FontWeight.w500,
                            color: AppColors.textMuted)),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3.5),
                  decoration: BoxDecoration(
                    color: chip.$2,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(chip.$1,
                      style: AppText.sans(
                          size: 9.5, weight: FontWeight.w800, color: chip.$3, letterSpacing: 0.4)),
                ),
                if (a.score != null) ...[
                  const SizedBox(height: 5),
                  Text(a.score!, style: AppText.mono(size: 12, color: AppColors.textSecondary)),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// (etiket, arka plan, ön plan)
  (String, Color, Color) _statusChip(String status) {
    switch (status) {
      case 'won':
        return ('KAZANDI', AppColors.primary.withValues(alpha: 0.20), AppColors.primary);
      case 'lost':
        return ('KAYBETTİ', AppColors.danger.withValues(alpha: 0.22), AppColors.dangerSoft);
      default:
        return ('TAKİPTE', AppColors.gold.withValues(alpha: 0.20), AppColors.gold);
    }
  }

  String _date(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso.replaceFirst(' ', 'T'));
    if (dt == null) return '';
    return DateFormat('d MMM', 'tr_TR').format(dt);
  }

  Widget _empty(WidgetRef ref) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.insights, size: 44, color: AppColors.textMuted),
              const SizedBox(height: 10),
              Text('Henüz maç incelemediniz.',
                  style: AppText.sans(size: 13, color: AppColors.textSecondary)),
              const SizedBox(height: 4),
              Text('Bir maç detayına girin; analizleriniz burada biriksin.',
                  textAlign: TextAlign.center,
                  style: AppText.sans(
                      size: 11, weight: FontWeight.w500, color: AppColors.textMuted)),
              TextButton(
                onPressed: () => ref.invalidate(myAnalysesProvider),
                child: const Text('Yenile'),
              ),
            ],
          ),
        ),
      );
}
