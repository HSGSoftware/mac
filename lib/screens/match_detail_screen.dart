import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/constants.dart';
import '../core/theme.dart';
import '../models/analysis.dart';
import '../models/match_stats.dart';
import '../providers/providers.dart';
import '../services/api_client.dart';
import '../widgets/badges.dart';
import '../widgets/bars.dart';
import '../widgets/odds_box.dart';
import '../widgets/paywall_sheet.dart';

class MatchDetailScreen extends ConsumerStatefulWidget {
  final int matchId;
  const MatchDetailScreen({super.key, required this.matchId});
  @override
  ConsumerState<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends ConsumerState<MatchDetailScreen> {
  Analysis? _analysis;
  bool _analyzing = false;
  int _stage = 0;
  Timer? _stageTimer;

  static const _stages = [
    'Maç verileri toplanıyor…',
    'Son maçların formu inceleniyor…',
    'Karşılaşma geçmişi (H2H) analiz ediliyor…',
    'Güncel oranlar değerlendiriliyor…',
    'Değerli oranlar (value bet) hesaplanıyor…',
    'Model olasılıkları hesaplıyor…',
  ];

  @override
  void dispose() {
    _stageTimer?.cancel();
    super.dispose();
  }

  Future<void> _runAnalysis() async {
    setState(() {
      _analyzing = true;
      _stage = 0;
    });
    final started = DateTime.now();
    _stageTimer = Timer.periodic(const Duration(milliseconds: 1500), (t) {
      if (_stage < _stages.length - 1) setState(() => _stage++);
    });
    try {
      final data = await ref
          .read(apiClientProvider)
          .post('/matches/${widget.matchId}/analyze');
      final result = Analysis.fromJson(Map<String, dynamic>.from(data['analysis']));
      const minShow = Duration(seconds: 7);
      final elapsed = DateTime.now().difference(started);
      if (elapsed < minShow) await Future.delayed(minShow - elapsed);
      if (mounted) setState(() => _analysis = result);
      ref.read(authProvider.notifier).refreshMe();
    } on ApiException catch (e) {
      if (e.code == 'limit_reached') {
        if (mounted) showPaywall(context);
      } else if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      _stageTimer?.cancel();
      if (mounted) setState(() => _analyzing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(matchDetailProvider(widget.matchId));
    final premium = ref.watch(authProvider).user?.isPremium ?? false;
    return Scaffold(
      body: detail.when(
        loading: () => const Center(
            child: CircularProgressIndicator(color: AppColors.primary)),
        error: (e, _) => Center(child: Text('Hata: $e')),
        data: (d) {
          final analysis = _analysis ?? d.analysis;
          final m = d.match;
          final home = m['home']?['name']?.toString() ?? '-';
          final away = m['away']?['name']?.toString() ?? '-';
          final league = m['league']?['name']?.toString() ?? '';
          final isLive = m['status'] == 'live';
          final score = m['score'];
          final stats = MatchStats.fromMap(d.stats);
          final impliedMs = _impliedMs(d.odds);
          final providerLine = analysis?.modelName != null
              ? '\n${analysis!.provider} · ${analysis!.modelName}'
              : '';

          return Column(
            children: [
              _header(context, home, away, league, isLive, score, m['minute']?.toString()),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                  children: [
                    _sectionHead('Kazanma olasılıkları',
                        trailing: analysis != null ? 'model vs. oranın iması' : null),
                    const SizedBox(height: 9),
                    ..._outcomeCards(d.odds, impliedMs, analysis, home, away),
                    if (analysis == null && !_analyzing) ...[
                      const SizedBox(height: 12),
                      _analyzeCta(premium),
                    ],
                    if (_analyzing) ...[
                      const SizedBox(height: 12),
                      _analyzingCard(),
                    ],
                    if (analysis != null) ...[
                      const SizedBox(height: 16),
                      _summaryCard(analysis),
                      if (analysis.reasons.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        _reasonsCard(analysis),
                      ],
                      ..._valueSection(analysis),
                      ..._marketAnalysesSection(analysis),
                    ],
                    if (isLive && stats.live.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      _sectionHead('Canlı istatistikler'),
                      const SizedBox(height: 10),
                      ...stats.live.map((s) => Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: ComparisonBar(
                                name: s.name, home: s.home, away: s.away, max: s.max),
                          )),
                    ],
                    if (!stats.formHome.isEmpty || !stats.formAway.isEmpty) ...[
                      const SizedBox(height: 18),
                      _formSection(stats, home, away),
                    ],
                    if (stats.h2h.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      _sectionHead('Aralarındaki son maçlar'),
                      const SizedBox(height: 9),
                      ...stats.h2h.map(_h2hRow),
                    ],
                    if (stats.season.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      _sectionHead('Sezon karşılaştırması'),
                      const SizedBox(height: 10),
                      ...stats.season.map((s) => Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: ComparisonBar(
                                name: s.name, home: s.home, away: s.away, max: s.max),
                          )),
                    ],
                    if (d.markets.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      _AllMarkets(count: d.markets.length, markets: d.markets),
                    ],
                    const SizedBox(height: 18),
                    Text(
                      'Analizler yatırım tavsiyesi değildir · 18+$providerLine',
                      textAlign: TextAlign.center,
                      style: AppText.sans(
                          size: 10,
                          weight: FontWeight.w500,
                          color: AppColors.textMuted),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  // ---------------- Bölümler ----------------

  Widget _header(BuildContext context, String home, String away, String league,
      bool isLive, dynamic score, String? minute) {
    return Container(
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        border: Border(bottom: BorderSide(color: AppColors.surface2)),
      ),
      padding: EdgeInsets.fromLTRB(
          6, MediaQuery.of(context).padding.top + 6, 12, 10),
      child: Row(
        children: [
          IconButton(
            onPressed: () => context.pop(),
            icon: const Icon(Icons.chevron_left, color: AppColors.primary),
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('$home — $away',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(size: 14, weight: FontWeight.w800)),
                const SizedBox(height: 2),
                Text(
                    isLive ? '$league · Şu an oynanıyor' : league,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(
                        size: 9.5,
                        weight: FontWeight.w500,
                        color: AppColors.textSecondary)),
              ],
            ),
          ),
          if (isLive && score != null) ...[
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppColors.danger.withValues(alpha: 0.5)),
              ),
              child: Column(
                children: [
                  Text('${score['home']} - ${score['away']}',
                      style: AppText.mono(size: 15)),
                  Text(minute != null ? "$minute' CANLI" : 'CANLI',
                      style: AppText.mono(size: 8.5, color: AppColors.danger)),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _outcomeCards(Map<String, double> odds, Map<String, int> implied,
      Analysis? analysis, String home, String away) {
    const codes = ['MS1', 'MSX', 'MS2'];
    final cards = <Widget>[];
    for (final code in codes) {
      final odd = odds[code];
      if (odd == null) continue;
      final imp = implied[code];
      final ma = analysis?.marketFor(code);
      final model = ma?.olasilik;
      final edge = (model != null && imp != null) ? model - imp : null;
      final isValue = ma?.degerVarMi ?? false;
      cards.add(_outcomeCard(
        label: msShort[code] ?? code,
        name: outcomeName(code, home: home, away: away),
        odd: odd,
        implied: imp,
        model: model,
        edge: edge,
        isValue: isValue,
      ));
    }
    if (cards.isEmpty) {
      cards.add(Padding(
        padding: const EdgeInsets.symmetric(vertical: 12),
        child: Text('Bu maç için maç sonucu oranı bulunamadı.',
            style: AppText.sans(size: 12, color: AppColors.textSecondary)),
      ));
    }
    return cards;
  }

  Widget _outcomeCard({
    required String label,
    required String name,
    required double odd,
    required int? implied,
    required int? model,
    required int? edge,
    required bool isValue,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 9),
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
            color: isValue ? AppColors.primary : AppColors.surface2),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SizedBox(
                  width: 16,
                  child: Text(label, style: AppText.mono(size: 11.5, color: AppColors.textSecondary))),
              const SizedBox(width: 4),
              Expanded(
                child: Text(name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
              ),
              if (isValue && edge != null) ...[
                ValueBadge(edge: edge),
                const SizedBox(width: 8),
              ],
              Text('@${odd.toStringAsFixed(2)}', style: AppText.mono(size: 13.5)),
            ],
          ),
          if (model != null) ...[
            const SizedBox(height: 8),
            ProbabilityBar(
              modelPct: model,
              impliedPct: implied,
              color: isValue ? AppColors.primary : AppColors.primaryDark,
            ),
            const SizedBox(height: 5),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _kv('Model', '%$model'),
                _kv('Oranın iması', implied != null ? '%$implied' : '-'),
              ],
            ),
          ] else ...[
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _kv('Oranın iması', implied != null ? '%$implied' : '-'),
                Text('Model: analiz bekliyor',
                    style: AppText.sans(
                        size: 10,
                        weight: FontWeight.w600,
                        color: AppColors.textSecondary)),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _kv(String k, String v) => Text.rich(TextSpan(children: [
        TextSpan(
            text: '$k: ',
            style: AppText.sans(
                size: 10, weight: FontWeight.w500, color: AppColors.textSecondary)),
        TextSpan(text: v, style: AppText.mono(size: 10.5)),
      ]));

  Widget _analyzeCta(bool premium) {
    return Column(
      children: [
        SizedBox(
          width: double.infinity,
          child: ElevatedButton.icon(
            onPressed: _runAnalysis,
            icon: const Icon(Icons.auto_awesome, size: 18),
            label: const Text('Model Analizini Getir'),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          premium
              ? 'Premium: sınırsız analiz'
              : 'Ücretsiz planda günlük analiz hakkınızla',
          style: AppText.sans(
              size: 10, weight: FontWeight.w500, color: AppColors.textMuted),
        ),
      ],
    );
  }

  Widget _analyzingCard() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.surface2),
      ),
      child: Row(
        children: [
          const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary)),
          const SizedBox(width: 14),
          Expanded(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 350),
              child: Text(_stages[_stage],
                  key: ValueKey(_stage),
                  style: AppText.sans(size: 12.5, color: AppColors.textSecondary)),
            ),
          ),
        ],
      ),
    );
  }

  /// AI genel değerlendirme kartı: yorum + en güvenli tahmin + sürpriz + güven.
  Widget _summaryCard(Analysis a) {
    Widget chip(IconData icon, String label, String value, {Color? color}) =>
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
          decoration: BoxDecoration(
            color: AppColors.oddCell,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: AppColors.oddBorder),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 13, color: color ?? AppColors.textSecondary),
              const SizedBox(width: 5),
              Text('$label: ',
                  style: AppText.sans(
                      size: 10, weight: FontWeight.w500, color: AppColors.textSecondary)),
              Text(value,
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w800, color: color ?? AppColors.textPrimary)),
            ],
          ),
        );

    String surprise(String? s) {
      switch (s) {
        case 'dusuk':
          return 'Düşük';
        case 'orta':
          return 'Orta';
        case 'yuksek':
          return 'Yüksek';
        default:
          return s ?? '-';
      }
    }

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
              const Icon(Icons.auto_awesome, size: 15, color: AppColors.primary),
              const SizedBox(width: 7),
              Expanded(
                child: Text('AI GENEL DEĞERLENDİRME',
                    style: AppText.sans(
                        size: 10.5,
                        weight: FontWeight.w800,
                        color: AppColors.primary,
                        letterSpacing: 0.8)),
              ),
              if (a.confidence != null)
                Text('Güven ${a.confidence}/10',
                    style: AppText.mono(size: 10.5, color: AppColors.primary)),
            ],
          ),
          if (a.generalNote != null && a.generalNote!.isNotEmpty) ...[
            const SizedBox(height: 9),
            Text(a.generalNote!,
                style: AppText.sans(
                    size: 12.5,
                    weight: FontWeight.w500,
                    color: AppColors.textPrimary)),
          ],
          const SizedBox(height: 10),
          Wrap(
            spacing: 7,
            runSpacing: 7,
            children: [
              if (a.safestPick != null)
                chip(Icons.verified, 'En güvenli', marketLabel(a.safestPick!),
                    color: AppColors.primary),
              if (a.surpriseLevel != null)
                chip(Icons.bolt, 'Sürpriz', surprise(a.surpriseLevel),
                    color: AppColors.gold),
              if (a.isRisky)
                chip(Icons.warning_amber_rounded, 'Uyarı', 'Riskli maç',
                    color: AppColors.danger),
            ],
          ),
        ],
      ),
    );
  }

  /// Market analizi görünen adı: kod ise Türkçe etiket, değilse ad · seçenek.
  String _maLabel(MarketAnalysis m) {
    final known = marketLabels[m.market];
    if (known != null) return known;
    return (m.secenek != null && m.secenek!.isNotEmpty)
        ? '${m.market} · ${m.secenek}'
        : m.market;
  }

  /// "Değer fırsatları": modelin orandan yüksek olasılık verdiği seçimler.
  List<Widget> _valueSection(Analysis a) {
    final values = a.markets.where((m) => m.degerVarMi).toList()
      ..sort((x, y) => (y.degerFarki ?? 0).compareTo(x.degerFarki ?? 0));
    if (values.isEmpty) return const [];
    return [
      const SizedBox(height: 18),
      _sectionHead('Değer fırsatları', trailing: 'model > oranın iması'),
      const SizedBox(height: 9),
      ...values.take(5).map((m) => _marketAnalysisCard(m, highlight: true)),
    ];
  }

  /// Tüm market analizleri (MS zaten üstte bar olarak var; burada gerekçeleriyle hepsi).
  List<Widget> _marketAnalysesSection(Analysis a) {
    final rest = a.markets
        .where((m) => m.olasilik != null && !m.degerVarMi)
        .toList();
    if (rest.isEmpty) return const [];
    return [
      const SizedBox(height: 18),
      _sectionHead('Market analizleri (${a.markets.where((m) => m.olasilik != null).length})'),
      const SizedBox(height: 9),
      ...rest.map((m) => _marketAnalysisCard(m)),
    ];
  }

  /// Tek market analizi kartı: ad + oran + olasılık barı + gerekçe.
  Widget _marketAnalysisCard(MarketAnalysis m, {bool highlight = false}) {
    final implied = m.impliedOlasilik?.round();
    return Container(
      margin: const EdgeInsets.only(bottom: 9),
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
            color: highlight ? AppColors.primary : AppColors.surface2),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(_maLabel(m),
                    style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
              ),
              if (m.degerVarMi && m.degerFarki != null) ...[
                ValueBadge(edge: m.degerFarki!.round()),
                const SizedBox(width: 8),
              ],
              if (m.oran != null)
                Text('@${m.oran!.toStringAsFixed(2)}', style: AppText.mono(size: 13)),
            ],
          ),
          if (m.olasilik != null) ...[
            const SizedBox(height: 8),
            ProbabilityBar(
              modelPct: m.olasilik!,
              impliedPct: implied,
              color: m.degerVarMi ? AppColors.primary : AppColors.primaryDark,
            ),
            const SizedBox(height: 5),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _kv('Model', '%${m.olasilik}'),
                _kv('Oranın iması', implied != null ? '%$implied' : '-'),
              ],
            ),
          ],
          if (m.gerekce != null && m.gerekce!.isNotEmpty) ...[
            const SizedBox(height: 7),
            Text(m.gerekce!,
                style: AppText.sans(
                    size: 11,
                    weight: FontWeight.w500,
                    color: AppColors.textSecondary)),
          ],
        ],
      ),
    );
  }

  Widget _reasonsCard(Analysis a) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.accentFaint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.accentDim),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text('MODEL NEDEN BÖYLE DÜŞÜNÜYOR?',
                    style: AppText.sans(
                        size: 10.5,
                        weight: FontWeight.w800,
                        color: AppColors.primary,
                        letterSpacing: 0.8)),
              ),
              if (a.confidence != null)
                Text('Güven: ${a.confidence}/10',
                    style: AppText.mono(size: 10.5, color: AppColors.primary)),
            ],
          ),
          const SizedBox(height: 10),
          if (a.reasons.isNotEmpty)
            ...a.reasons.map((r) => Padding(
                  padding: const EdgeInsets.only(bottom: 7),
                  child: Text.rich(TextSpan(children: [
                    TextSpan(
                        text: '${r.tag}: ',
                        style: AppText.sans(size: 12, weight: FontWeight.w800)),
                    TextSpan(
                        text: r.text,
                        style: AppText.sans(
                            size: 12,
                            weight: FontWeight.w500,
                            color: AppColors.textPrimary)),
                  ])),
                ))
          else if (a.generalNote != null)
            Text(a.generalNote!,
                style: AppText.sans(
                    size: 12, weight: FontWeight.w500, color: AppColors.textPrimary)),
        ],
      ),
    );
  }

  Widget _formSection(MatchStats stats, String home, String away) {
    Widget box(String team, TeamForm f) => Expanded(
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.surface2),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('$team · son 5',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(
                        size: 10, weight: FontWeight.w700, color: AppColors.textSecondary)),
                const SizedBox(height: 8),
                Row(
                  children: f.results
                      .map((l) => Padding(
                            padding: const EdgeInsets.only(right: 4),
                            child: FormChip(letter: l),
                          ))
                      .toList(),
                ),
                if (f.note != null) ...[
                  const SizedBox(height: 7),
                  Text(f.note!,
                      style: AppText.sans(
                          size: 10.5,
                          weight: FontWeight.w500,
                          color: AppColors.textSecondary)),
                ],
              ],
            ),
          ),
        );
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        box(home, stats.formHome),
        const SizedBox(width: 9),
        box(away, stats.formAway),
      ],
    );
  }

  Widget _h2hRow(H2hItem h) {
    final color = h.win == 'd' ? AppColors.textSecondary : AppColors.textPrimary;
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.surface2),
      ),
      child: Row(
        children: [
          SizedBox(
              width: 54,
              child: Text(h.date,
                  style: AppText.mono(size: 10, weight: FontWeight.w500, color: AppColors.textMuted))),
          Expanded(
            child: Text(h.fixture,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppText.sans(size: 11.5, weight: FontWeight.w600)),
          ),
          Text(h.score, style: AppText.mono(size: 12, color: color)),
        ],
      ),
    );
  }

  Widget _sectionHead(String title, {String? trailing}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(child: Text(title.toUpperCase(), style: AppText.section())),
        if (trailing != null)
          Text(trailing,
              style: AppText.sans(
                  size: 9.5, weight: FontWeight.w500, color: AppColors.textMuted)),
      ],
    );
  }

  Map<String, int> _impliedMs(Map<String, double> odds) {
    final o1 = odds['MS1'], ox = odds['MSX'], o2 = odds['MS2'];
    if (o1 == null || ox == null || o2 == null || o1 <= 0 || ox <= 0 || o2 <= 0) {
      return {};
    }
    final i1 = 1 / o1, ix = 1 / ox, i2 = 1 / o2;
    final s = i1 + ix + i2;
    return {
      'MS1': (i1 / s * 100).round(),
      'MSX': (ix / s * 100).round(),
      'MS2': (i2 / s * 100).round(),
    };
  }
}

/// "Tüm Marketler" genişleyebilir bölümü.
class _AllMarkets extends StatefulWidget {
  final int count;
  final List markets;
  const _AllMarkets({required this.count, required this.markets});
  @override
  State<_AllMarkets> createState() => _AllMarketsState();
}

class _AllMarketsState extends State<_AllMarkets> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          onTap: () => setState(() => _open = !_open),
          child: Row(
            children: [
              Expanded(
                child: Text('TÜM MARKETLER (${widget.count})', style: AppText.section()),
              ),
              Icon(_open ? Icons.expand_less : Icons.expand_more,
                  color: AppColors.textSecondary, size: 20),
            ],
          ),
        ),
        if (_open) ...[
          const SizedBox(height: 8),
          ...widget.markets.map((m) => Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppColors.surface2),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(m.name,
                        style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 7,
                      runSpacing: 7,
                      children: m.outcomes
                          .map<Widget>((o) => ConstrainedBox(
                                constraints: const BoxConstraints(minWidth: 74),
                                child: OddsBox(label: o.label, value: o.odd, compact: true),
                              ))
                          .toList(),
                    ),
                  ],
                ),
              )),
        ],
      ],
    );
  }
}
