import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../models/analysis.dart';
import '../providers/providers.dart';
import '../services/api_client.dart';
import '../widgets/analysis_view.dart';

class MatchDetailScreen extends ConsumerWidget {
  final int matchId;
  const MatchDetailScreen({super.key, required this.matchId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(matchDetailProvider(matchId));
    return Scaffold(
      appBar: AppBar(title: const Text('Maç Detayı')),
      body: detail.when(
        loading: () => const Center(
            child: CircularProgressIndicator(color: AppColors.primary)),
        error: (e, _) => Center(child: Text('Hata: $e')),
        data: (d) {
          final match = d.match;
          return DefaultTabController(
            length: 3,
            child: Column(
              children: [
                _header(match),
                const TabBar(
                  labelColor: AppColors.primary,
                  unselectedLabelColor: AppColors.textSecondary,
                  indicatorColor: AppColors.primary,
                  tabs: [
                    Tab(text: 'Oranlar'),
                    Tab(text: 'Karşılaştırma'),
                    Tab(text: 'AI Analiz'),
                  ],
                ),
                Expanded(
                  child: TabBarView(
                    children: [
                      _OddsTab(odds: d.odds),
                      _StatsTab(stats: d.stats),
                      AnalysisTab(matchId: matchId, initial: d.analysis),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _header(Map<String, dynamic> m) {
    final home = m['home']?['name'] ?? '-';
    final away = m['away']?['name'] ?? '-';
    final league = m['league']?['name'] ?? '';
    final score = m['score'];
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      color: AppColors.surface,
      child: Column(
        children: [
          Text(league,
              style: const TextStyle(color: AppColors.textSecondary, fontSize: 12)),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text(home,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16)),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Text(
                  score != null ? '${score['home']} - ${score['away']}' : 'VS',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 20,
                      color: AppColors.accent),
                ),
              ),
              Expanded(
                child: Text(away,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16)),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _OddsTab extends StatelessWidget {
  final Map<String, double> odds;
  const _OddsTab({required this.odds});

  @override
  Widget build(BuildContext context) {
    if (odds.isEmpty) {
      return const Center(
          child: Text('Oran bilgisi yok.',
              style: TextStyle(color: AppColors.textSecondary)));
    }
    final entries = odds.entries.toList();
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: entries.length,
      separatorBuilder: (_, __) => const Divider(color: AppColors.surface2),
      itemBuilder: (c, i) {
        final e = entries[i];
        return Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(marketLabel(e.key)),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.surface2,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(e.value.toStringAsFixed(2),
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, color: AppColors.accent)),
            ),
          ],
        );
      },
    );
  }
}

class _StatsTab extends StatelessWidget {
  final Map<String, dynamic> stats;
  const _StatsTab({required this.stats});

  @override
  Widget build(BuildContext context) {
    if (stats.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'Bu maç için henüz karşılaştırma verisi çekilmemiş.\n'
            'AI Analiz sekmesinden analiz başlatırsanız veriler toplanır.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppColors.textSecondary),
          ),
        ),
      );
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: stats.entries.map((e) {
        return Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_statTitle(e.key),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, color: AppColors.accent)),
                const SizedBox(height: 6),
                Text(e.value.toString(),
                    style: const TextStyle(
                        color: AppColors.textSecondary, fontSize: 12)),
              ],
            ),
          ),
        );
      }).toList(),
    );
  }

  String _statTitle(String key) {
    switch (key) {
      case 'h2h':
        return 'Aralarındaki Maçlar (H2H)';
      case 'form_home':
        return 'Ev Sahibi Form';
      case 'form_away':
        return 'Deplasman Form';
      case 'standings':
        return 'Puan Durumu';
      default:
        return key;
    }
  }
}
