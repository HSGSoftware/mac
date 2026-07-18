import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../providers/providers.dart';

/// AI tahmin isabet istatistikleri (güven inşa eder).
class StatsScreen extends ConsumerWidget {
  const StatsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final stats = ref.watch(successRateProvider);
    return stats.when(
      loading: () =>
          const Center(child: CircularProgressIndicator(color: AppColors.primary)),
      error: (e, _) => Center(child: Text('Hata: $e')),
      data: (data) {
        final overall = Map<String, dynamic>.from(data['overall'] ?? {});
        final byMarket = (data['by_market'] as List?) ?? [];
        final rate = overall['rate'];
        return RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () async => ref.invalidate(successRateProvider),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    children: [
                      const Text('Genel AI İsabet Oranı',
                          style: TextStyle(color: AppColors.textSecondary)),
                      const SizedBox(height: 8),
                      Text(rate != null ? '%$rate' : '—',
                          style: const TextStyle(
                              fontSize: 44,
                              fontWeight: FontWeight.w800,
                              color: AppColors.primary)),
                      Text('${overall['correct'] ?? 0} / ${overall['total'] ?? 0} tahmin doğru',
                          style: const TextStyle(
                              color: AppColors.textSecondary, fontSize: 12)),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              const Text('Markete Göre İsabet',
                  style: TextStyle(
                      fontWeight: FontWeight.bold, color: AppColors.accent)),
              const SizedBox(height: 8),
              if (byMarket.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text(
                    'Yeterli veri birikince buraya market bazlı isabet oranları gelecek.',
                    style: TextStyle(color: AppColors.textSecondary),
                  ),
                )
              else
                ...byMarket.map((m) {
                  final mm = Map<String, dynamic>.from(m);
                  final r = mm['rate'];
                  return Card(
                    child: ListTile(
                      title: Text(marketLabel(mm['market'] ?? '')),
                      subtitle: Text('${mm['correct']}/${mm['total']} doğru'),
                      trailing: Text(r != null ? '%$r' : '—',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              color: AppColors.primary,
                              fontSize: 16)),
                    ),
                  );
                }),
            ],
          ),
        );
      },
    );
  }
}
