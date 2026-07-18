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
      // Önbellekten dönen (daha önce üretilmiş) analizde sahte bekleme yapma
      if (data['cached'] != true) {
        const minShow = Duration(seconds: 7);
        final elapsed = DateTime.now().difference(started);
        if (elapsed < minShow) await Future.delayed(minShow - elapsed);
      }
      if (mounted) setState(() => _analysis = result);
      ref.read(authProvider.notifier).refreshMe();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.code == 'insufficient_tokens' || e.code == 'limit_reached') {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
        showPaywall(context);
      } else if (e.code == 'live_locked') {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
        showPaywall(context, highlightTier: 3);
      } else {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      _stageTimer?.cancel();
      if (mounted) setState(() => _analyzing = false);
    }
  }

  /// Bir market grubunu token harcayarak açar (onay diyaloğuyla).
  Future<void> _unlockGroup(MarketGroupInfo g, int? tokensLeft) async {
    final left = ref.read(authProvider).user?.tokensLeft ?? tokensLeft ?? 0;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text('${g.name} grubunu aç',
            style: AppText.sans(size: 15, weight: FontWeight.w800)),
        content: Text(
          'Bu grubu açmak ${g.cost} token harcar (kalan: $left).\n'
          'Açılan grup bu maç için tekrar ücret alınmadan görüntülenir.\n'
          'Token hakkınız her gün yenilenir.',
          style: AppText.sans(size: 12.5, color: AppColors.textSecondary),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Vazgeç'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('${g.cost} token harca'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    try {
      await ref
          .read(apiClientProvider)
          .post('/matches/${widget.matchId}/unlock-group', data: {'group': g.key});
      ref.invalidate(matchDetailProvider(widget.matchId));
      ref.read(authProvider.notifier).refreshMe();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
      if (e.code == 'insufficient_tokens') {
        showPaywall(context);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(matchDetailProvider(widget.matchId));
    final tier = ref.watch(authProvider).user?.tier ?? 0;
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
                      _analyzeCta(tier, d, isLive),
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
                      ..._valueSection(analysis, tier, d.unlockedGroupKeys),
                      ..._marketAnalysesSection(analysis, tier, d.unlockedGroupKeys),
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
                    if (d.marketGroups.any((g) => g.count > 0)) ...[
                      const SizedBox(height: 18),
                      _GroupedMarkets(
                        markets: d.markets,
                        groups: d.marketGroups,
                        onUnlock: (g) => _unlockGroup(g, d.tokensLeft),
                      ),
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

  Widget _analyzeCta(int tier, MatchDetail d, bool isLive) {
    // Canlı maç AI tahminleri yalnızca Altın pakette
    if (isLive && tier < 3) {
      return Column(
        children: [
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: () => showPaywall(context, highlightTier: 3),
              icon: const Icon(Icons.lock_outline, size: 18),
              label: const Text('Canlı AI Tahminleri — Altın Paket'),
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Canlı maçlarda AI tahminleri Altın pakete özeldir.',
            style: AppText.sans(
                size: 10, weight: FontWeight.w500, color: AppColors.textMuted),
          ),
        ],
      );
    }
    final cost = isLive
        ? (d.tokenCosts['live_analysis'] ?? 40)
        : (d.tokenCosts['analysis'] ?? 25);
    final left = ref.watch(authProvider).user?.tokensLeft ?? d.tokensLeft ?? 0;
    final label = d.analysisExists
        ? 'Analizi Aç ($cost token)'
        : 'Model Analizini Getir ($cost token)';
    return Column(
      children: [
        SizedBox(
          width: double.infinity,
          child: ElevatedButton.icon(
            onPressed: _runAnalysis,
            icon: const Icon(Icons.auto_awesome, size: 18),
            label: Text(label),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          'Kalan token: $left / gün · token hakkı her gün yenilenir',
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

  /// Analiz kaydı kilitli mi? Grubu token ile açıldıysa VEYA paket kademesi
  /// grubu kapsıyorsa görünür.
  bool _maLocked(MarketAnalysis m, int tier, Set<String> unlockedKeys) {
    final key = analysisGroupKeyFor(m.market);
    if (unlockedKeys.contains(key)) return false;
    return marketGroupDef(key).tier > tier;
  }

  /// Kilitli analiz sayısını gösteren ipucu kartı (token/paket ile açılır).
  Widget _lockedHint(int count, int tier) {
    final next = (tier + 1).clamp(1, 3).toInt();
    return GestureDetector(
      onTap: () => showPaywall(context, highlightTier: next),
      child: Container(
        margin: const EdgeInsets.only(bottom: 9),
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.goldDim,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.gold.withValues(alpha: 0.5)),
        ),
        child: Row(
          children: [
            const Icon(Icons.lock_outline, size: 17, color: AppColors.gold),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                '+$count market analizi daha var — ilgili market grubunu token ile açın veya paketinizi yükseltin.',
                style: AppText.sans(
                    size: 12, weight: FontWeight.w700, color: const Color(0xFFE7CE8B)),
              ),
            ),
            Text('Paketler ›',
                style: AppText.sans(
                    size: 11, weight: FontWeight.w800, color: AppColors.gold)),
          ],
        ),
      ),
    );
  }

  /// "Değer fırsatları": modelin orandan yüksek olasılık verdiği seçimler.
  List<Widget> _valueSection(Analysis a, int tier, Set<String> unlockedKeys) {
    final all = a.markets.where((m) => m.degerVarMi).toList()
      ..sort((x, y) => (y.degerFarki ?? 0).compareTo(x.degerFarki ?? 0));
    if (all.isEmpty) return const [];
    final open = all.where((m) => !_maLocked(m, tier, unlockedKeys)).toList();
    final visible = open.take(5).toList();
    final lockedCount = all.length - open.length;
    return [
      const SizedBox(height: 18),
      _sectionHead('Değer fırsatları', trailing: 'model > oranın iması'),
      const SizedBox(height: 9),
      ...visible.map((m) => _marketAnalysisCard(m, highlight: true)),
      if (lockedCount > 0) _lockedHint(lockedCount, tier),
    ];
  }

  /// Market analizleri — açılan gruplar + paketin kapsadığı gruplar görünür.
  List<Widget> _marketAnalysesSection(
      Analysis a, int tier, Set<String> unlockedKeys) {
    final rest = a.markets
        .where((m) => m.olasilik != null && !m.degerVarMi)
        .toList();
    if (rest.isEmpty) return const [];
    final visible =
        rest.where((m) => !_maLocked(m, tier, unlockedKeys)).toList();
    final lockedCount = rest.length - visible.length;
    return [
      const SizedBox(height: 18),
      _sectionHead(
          'Market analizleri (${a.markets.where((m) => m.olasilik != null).length})'),
      const SizedBox(height: 9),
      ...visible.map((m) => _marketAnalysisCard(m)),
      if (lockedCount > 0) _lockedHint(lockedCount, tier),
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

/// Marketler, gruplara ayrılmış ve TOKEN kilidiyle gösterilir.
/// Kilitli grup başlığında token maliyeti yazar; dokununca [onUnlock] çağrılır.
class _GroupedMarkets extends StatefulWidget {
  final List<BetMarket> markets; // yalnızca açılmış grupların marketleri
  final List<MarketGroupInfo> groups;
  final void Function(MarketGroupInfo) onUnlock;
  const _GroupedMarkets({
    required this.markets,
    required this.groups,
    required this.onUnlock,
  });
  @override
  State<_GroupedMarkets> createState() => _GroupedMarketsState();
}

class _GroupedMarketsState extends State<_GroupedMarkets> {
  final Set<String> _open = {'ana'}; // Ana Marketler varsayılan açık

  @override
  Widget build(BuildContext context) {
    // Açık marketleri gruplarına dağıt (sunucu 'grup' alanını gönderir)
    final byGroup = <String, List<BetMarket>>{};
    for (final m in widget.markets) {
      final key = m.group ?? marketGroupKeyFor(m.name);
      (byGroup[key] ??= []).add(m);
    }

    final children = <Widget>[
      Text('MARKET GRUPLARI', style: AppText.section()),
      const SizedBox(height: 8),
    ];
    for (final g in widget.groups) {
      if (g.count == 0) continue;
      final isOpen = g.unlocked && _open.contains(g.key);
      children.add(_groupHeader(g, isOpen));
      if (isOpen) {
        children.addAll((byGroup[g.key] ?? []).map((m) => _marketCard(m)));
      }
      children.add(const SizedBox(height: 6));
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: children,
    );
  }

  Widget _groupHeader(MarketGroupInfo g, bool isOpen) {
    final unlocked = g.unlocked;
    return GestureDetector(
      onTap: () {
        if (!unlocked) {
          widget.onUnlock(g);
          return;
        }
        setState(() => isOpen ? _open.remove(g.key) : _open.add(g.key));
      },
      child: Container(
        margin: const EdgeInsets.only(bottom: 6),
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
        decoration: BoxDecoration(
          color: unlocked ? AppColors.surface : AppColors.goldDim,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
              color: unlocked
                  ? AppColors.surface2
                  : AppColors.gold.withValues(alpha: 0.5)),
        ),
        child: Row(
          children: [
            Icon(
              unlocked
                  ? (isOpen ? Icons.expand_less : Icons.expand_more)
                  : Icons.lock_outline,
              size: 18,
              color: unlocked ? AppColors.textSecondary : AppColors.gold,
            ),
            const SizedBox(width: 9),
            Expanded(
              child: Text('${g.name} (${g.count})',
                  style: AppText.sans(
                      size: 13,
                      weight: FontWeight.w700,
                      color: unlocked
                          ? AppColors.textPrimary
                          : const Color(0xFFE7CE8B))),
            ),
            if (!unlocked)
              Text('${g.cost} token ile aç',
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w800, color: AppColors.gold)),
          ],
        ),
      ),
    );
  }

  Widget _marketCard(dynamic m) => Container(
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
      );
}
