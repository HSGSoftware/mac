import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/theme.dart';
import '../models/analysis.dart';
import '../providers/providers.dart';
import '../services/api_client.dart';
import '../widgets/analysis_view.dart';
import '../widgets/odds_box.dart';

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

  static const _groups = [
    ['Maç Sonucu', ['MS1', 'MSX', 'MS2'], ['1', 'X', '2']],
    ['Çifte Şans', ['CS1X', 'CS12', 'CSX2'], ['1-X', '1-2', 'X-2']],
    ['2.5 Gol', ['ALT25', 'UST25'], ['Alt', 'Üst']],
    ['1.5 Gol', ['ALT15', 'UST15'], ['Alt', 'Üst']],
    ['3.5 Gol', ['ALT35', 'UST35'], ['Alt', 'Üst']],
    ['Karşılıklı Gol', ['KGVAR', 'KGYOK'], ['Var', 'Yok']],
  ];

  @override
  Widget build(BuildContext context) {
    if (odds.isEmpty) {
      return const _EmptyOdds();
    }
    final sections = <Widget>[];
    for (final g in _groups) {
      final keys = g[1] as List<String>;
      final labels = g[2] as List<String>;
      if (!keys.any((k) => odds.containsKey(k))) continue;
      sections.add(Padding(
        padding: const EdgeInsets.fromLTRB(4, 14, 4, 8),
        child: Text(g[0] as String,
            style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: AppColors.accent)),
      ));
      sections.add(Row(
        children: [
          for (var i = 0; i < keys.length; i++) ...[
            Expanded(child: OddsBox(label: labels[i], value: odds[keys[i]])),
            if (i != keys.length - 1) const SizedBox(width: 8),
          ],
        ],
      ));
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: const [
            Icon(Icons.bolt, size: 16, color: AppColors.warning),
            SizedBox(width: 6),
            Text('Güncel İddaa Oranları',
                style: TextStyle(fontWeight: FontWeight.bold)),
          ],
        ),
        ...sections,
      ],
    );
  }
}

class _EmptyOdds extends StatelessWidget {
  const _EmptyOdds();
  @override
  Widget build(BuildContext context) => const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('Bu maç için oran bilgisi bulunamadı.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textSecondary)),
        ),
      );
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
