import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../models/analysis.dart';
import '../providers/providers.dart';
import '../services/api_client.dart';
import 'probability_ring.dart';

/// Maç detayındaki "AI Analiz" sekmesi.
class AnalysisTab extends ConsumerStatefulWidget {
  final int matchId;
  final bool hasAnalysis;
  const AnalysisTab({super.key, required this.matchId, this.hasAnalysis = false});

  @override
  ConsumerState<AnalysisTab> createState() => _AnalysisTabState();
}

class _AnalysisTabState extends ConsumerState<AnalysisTab> {
  Analysis? _analysis;
  bool _loading = false;
  String? _error;

  static const _stages = [
    'Maç verileri toplanıyor…',
    'Son maçların formu inceleniyor…',
    'Karşılaşma geçmişi (H2H) analiz ediliyor…',
    'Güncel oranlar değerlendiriliyor…',
    'Değerli oranlar (value bet) hesaplanıyor…',
    'Yapay zeka olasılıkları hesaplıyor…',
  ];
  int _stageIndex = 0;
  Timer? _stageTimer;

  @override
  void dispose() {
    _stageTimer?.cancel();
    super.dispose();
  }

  Future<void> _analyze() async {
    setState(() {
      _loading = true;
      _error = null;
      _stageIndex = 0;
    });
    final started = DateTime.now();
    _stageTimer = Timer.periodic(const Duration(milliseconds: 1600), (t) {
      if (_stageIndex < _stages.length - 1) {
        setState(() => _stageIndex++);
      }
    });
    try {
      final data = await ref
          .read(apiClientProvider)
          .post('/matches/${widget.matchId}/analyze');
      final result = Analysis.fromJson(Map<String, dynamic>.from(data['analysis']));
      // Önbellekten anında dönse bile kullanıcıya sıfırdan analiz hissi ver:
      // animasyonu en az bu süre kadar oynat.
      const minShow = Duration(seconds: 8);
      final elapsed = DateTime.now().difference(started);
      if (elapsed < minShow) {
        await Future.delayed(minShow - elapsed);
      }
      if (mounted) setState(() => _analysis = result);
      ref.read(authProvider.notifier).refreshMe();
    } on ApiException catch (e) {
      if (e.code == 'limit_reached') {
        if (mounted) _showPremiumSheet(e.message);
      } else {
        if (mounted) setState(() => _error = e.message);
      }
    } finally {
      _stageTimer?.cancel();
      if (mounted) setState(() => _loading = false);
    }
  }

  void _showPremiumSheet(String msg) {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (c) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.workspace_premium,
                size: 48, color: AppColors.gold),
            const SizedBox(height: 12),
            const Text('Premium\'a Geç',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(msg,
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppColors.textSecondary)),
            const SizedBox(height: 16),
            const _PremiumBenefit(text: 'Sınırsız AI analizi'),
            const _PremiumBenefit(text: 'Tüm marketler için detaylı gerekçe'),
            const _PremiumBenefit(text: 'Değerli oran (value bet) uyarıları'),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.gold),
                onPressed: () => Navigator.pop(c),
                child: const Text('Anladım'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_analysis != null) {
      return _ResultView(analysis: _analysis!);
    }
    if (_loading) {
      return _LoadingView(message: _stages[_stageIndex]);
    }
    return _StartView(error: _error, onStart: _analyze);
  }
}

class _StartView extends StatelessWidget {
  final String? error;
  final VoidCallback onStart;
  const _StartView({this.error, required this.onStart});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.auto_awesome, size: 56, color: AppColors.primary),
            const SizedBox(height: 16),
            const Text('Yapay Zeka Analizi',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Text(
              'Geçmiş maçlar, form ve oranlar incelenerek her seçeneğin '
              'kazanma olasılığı ve gerekçesi hesaplanır.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.textSecondary),
            ),
            if (error != null) ...[
              const SizedBox(height: 12),
              Text(error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppColors.danger)),
            ],
            const SizedBox(height: 24),
            ElevatedButton.icon(
              onPressed: onStart,
              icon: const Icon(Icons.auto_awesome),
              label: const Text('Analiz Et'),
            ),
          ],
        ),
      ),
    );
  }
}

class _LoadingView extends StatelessWidget {
  final String message;
  const _LoadingView({required this.message});
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const CircularProgressIndicator(color: AppColors.primary),
          const SizedBox(height: 20),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 400),
            child: Text(message,
                key: ValueKey(message),
                style: const TextStyle(color: AppColors.textSecondary)),
          ),
        ],
      ),
    );
  }
}

class _ResultView extends StatelessWidget {
  final Analysis analysis;
  const _ResultView({required this.analysis});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (analysis.generalNote != null)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.psychology, color: AppColors.primary, size: 18),
                      SizedBox(width: 6),
                      Text('Genel Değerlendirme',
                          style: TextStyle(fontWeight: FontWeight.bold)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(analysis.generalNote!),
                ],
              ),
            ),
          ),
        const SizedBox(height: 8),
        Row(
          children: [
            if (analysis.safestPick != null)
              Expanded(
                child: _InfoChip(
                  icon: Icons.verified,
                  color: AppColors.primary,
                  label: 'En güvenli',
                  value: marketLabel(analysis.safestPick!),
                ),
              ),
            if (analysis.surpriseLevel != null) ...[
              const SizedBox(width: 8),
              Expanded(
                child: _InfoChip(
                  icon: Icons.bolt,
                  color: AppColors.warning,
                  label: 'Sürpriz',
                  value: analysis.surpriseLevel!,
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 12),
        ...analysis.markets.map((m) => _MarketCard(market: m)),
        const SizedBox(height: 16),
        Text(
          'Sağlayıcı: ${analysis.provider} · ${analysis.modelName ?? ''}\n'
          'Analizler yatırım tavsiyesi değildir.',
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppColors.textSecondary, fontSize: 11),
        ),
      ],
    );
  }
}

class _MarketCard extends StatelessWidget {
  final MarketAnalysis market;
  const _MarketCard({required this.market});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(
          color: market.degerVarMi ? AppColors.gold : AppColors.surface2,
          width: market.degerVarMi ? 1.5 : 1,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ProbabilityRing(percent: market.olasilik ?? 0),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(marketLabel(market.market),
                            style:
                                const TextStyle(fontWeight: FontWeight.bold)),
                      ),
                      if (market.oran != null)
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: AppColors.surface2,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(market.oran!.toStringAsFixed(2),
                              style: const TextStyle(
                                  color: AppColors.accent,
                                  fontWeight: FontWeight.bold)),
                        ),
                    ],
                  ),
                  if (market.degerVarMi)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.gold.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '💎 Değerli Oran (+${market.degerFarki?.toStringAsFixed(0) ?? ''} puan)',
                          style: const TextStyle(
                              color: AppColors.gold,
                              fontSize: 11,
                              fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                  if (market.gerekce != null) ...[
                    const SizedBox(height: 6),
                    Text(market.gerekce!,
                        style: const TextStyle(
                            color: AppColors.textSecondary, fontSize: 13)),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final String value;
  const _InfoChip({
    required this.icon,
    required this.color,
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Row(
          children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label,
                      style: const TextStyle(
                          color: AppColors.textSecondary, fontSize: 11)),
                  Text(value,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PremiumBenefit extends StatelessWidget {
  final String text;
  const _PremiumBenefit({required this.text});
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          const Icon(Icons.check_circle, color: AppColors.primary, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(text)),
        ],
      ),
    );
  }
}
