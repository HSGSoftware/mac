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

/// Maç detay ekranı — KREDİ sistemi:
/// her market AYRI analiz edilir ve ayrı kredi tüketir; canlı maç analizleri
/// yalnızca Altın pakette. Oran grupları paket kademesine göre görünür.
class MatchDetailScreen extends ConsumerStatefulWidget {
  final int matchId;
  const MatchDetailScreen({super.key, required this.matchId});
  @override
  ConsumerState<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends ConsumerState<MatchDetailScreen> {
  /// Bu oturumda üretilen analizler (sunucudan gelenlerin üzerine yazılır).
  final Map<String, MarketAiAnalysis> _results = {};

  /// Şu an analiz edilen market anahtarları (buton spinner'ı için).
  final Set<String> _busy = {};

  /// Belirtilen marketi kredi harcayarak analiz ettirir.
  Future<void> _analyzeMarket(String marketKey) async {
    if (_busy.contains(marketKey)) return;
    setState(() => _busy.add(marketKey));
    try {
      final data = await ref.read(apiClientProvider).post(
        '/matches/${widget.matchId}/analyze-market',
        data: {'market_key': marketKey},
      );
      // Analiz hazır değilse: arka planda üretiliyor. Kullanıcı beklemez;
      // "Analizlerim"e düşer, hazır olunca orada görünür.
      if (data is Map && data['preparing'] == true) {
        if (mounted) {
          final msg = data['message']?.toString() ??
              'Analiz hazırlanıyor, birazdan "Analizlerim" bölümünde olacak.';
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(msg),
              duration: const Duration(seconds: 6),
            ),
          );
        }
        // Bir süre sonra maç detayını tazele ki hazır sonuç görünsün.
        Future.delayed(const Duration(seconds: 12), () {
          if (mounted) ref.invalidate(matchDetailProvider(widget.matchId));
        });
        return;
      }
      final result =
          MarketAiAnalysis.fromJson(Map<String, dynamic>.from(data['analysis']));
      if (mounted) setState(() => _results[marketKey] = result);
      ref.read(authProvider.notifier).refreshMe();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
      if (e.code == 'insufficient_credits') {
        showPaywall(context);
      } else if (e.code == 'live_locked') {
        showPaywall(context, highlightTier: 3);
      }
    } finally {
      if (mounted) setState(() => _busy.remove(marketKey));
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(matchDetailProvider(widget.matchId));
    final user = ref.watch(authProvider).user;
    final tier = user?.tier ?? 0;
    return Scaffold(
      body: detail.when(
        loading: () => const Center(
            child: CircularProgressIndicator(color: AppColors.primary)),
        error: (e, _) => Center(child: Text('Hata: $e')),
        data: (d) {
          final m = d.match;
          final home = m['home']?['name']?.toString() ?? '-';
          final away = m['away']?['name']?.toString() ?? '-';
          final league = m['league']?['name']?.toString() ?? '';
          final isLive = m['status'] == 'live';
          final score = m['score'];
          final stats = MatchStats.fromMap(d.stats);
          final impliedMs = _impliedMs(d.odds);
          // Sunucudan gelen (daha önce açılmış) + bu oturumda üretilen analizler
          final analyses = <String, MarketAiAnalysis>{
            for (final a in d.marketAnalyses) a.marketKey: a,
            ..._results,
          };
          final cost = isLive ? d.creditCostLiveMarket : d.creditCostMarket;
          final creditsLeft = user?.creditsLeft ?? d.creditsLeft ?? 0;
          final msAnalysis = analyses['MS'];

          return Column(
            children: [
              _header(context, home, away, league, isLive, score,
                  m['minute']?.toString(), creditsLeft, user != null),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                  children: [
                    _sectionHead('Kazanma olasılıkları',
                        trailing:
                            msAnalysis != null ? 'model vs. oranın iması' : null),
                    const SizedBox(height: 9),
                    ..._outcomeCards(d.odds, impliedMs, msAnalysis, home, away),
                    if (d.odds.containsKey('MS1')) ...[
                      const SizedBox(height: 10),
                      _analyzeButton(
                        marketKey: 'MS',
                        label: 'Maç Sonucu AI Analizi',
                        cost: cost,
                        isLive: isLive,
                        tier: tier,
                        hasResult: msAnalysis != null,
                        creditsLeft: creditsLeft,
                      ),
                    ],
                    if (msAnalysis != null) ...[
                      const SizedBox(height: 10),
                      _aiResultCard(msAnalysis, showOptions: false),
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
                    if (d.marketGroups.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      ..._groupedMarkets(d, analyses, isLive, tier, cost,
                          creditsLeft),
                    ],
                    const SizedBox(height: 18),
                    Text(
                      'Analizler yatırım tavsiyesi değildir · 18+',
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

  // ---------------- Başlık ----------------

  Widget _header(BuildContext context, String home, String away, String league,
      bool isLive, dynamic score, String? minute, int creditsLeft, bool loggedIn) {
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
          if (loggedIn) ...[
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
              decoration: BoxDecoration(
                color: AppColors.oddCell,
                borderRadius: BorderRadius.circular(9),
                border: Border.all(color: AppColors.oddBorder),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.bolt, size: 13, color: AppColors.primary),
                  const SizedBox(width: 3),
                  Text('$creditsLeft', style: AppText.mono(size: 12)),
                ],
              ),
            ),
          ],
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

  // ---------------- MS (1X2) kartları ----------------

  List<Widget> _outcomeCards(Map<String, double> odds, Map<String, int> implied,
      MarketAiAnalysis? ms, String home, String away) {
    const codes = ['MS1', 'MSX', 'MS2'];
    final cards = <Widget>[];
    for (final code in codes) {
      final odd = odds[code];
      if (odd == null) continue;
      final imp = implied[code];
      final opt = ms?.optionFor(code);
      final model = opt?.olasilik;
      final edge = (model != null && imp != null) ? model - imp : null;
      final isValue = opt?.degerVarMi ?? false;
      cards.add(_outcomeCard(
        label: msShort[code] ?? code,
        name: outcomeName(code, home: home, away: away),
        odd: odd,
        implied: imp,
        model: model,
        edge: edge,
        isValue: isValue,
        gerekce: opt?.gerekce,
        recommended: ms?.tavsiye == code,
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
    String? gerekce,
    bool recommended = false,
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
                  child: Text(label,
                      style: AppText.mono(
                          size: 11.5, color: AppColors.textSecondary))),
              const SizedBox(width: 4),
              Expanded(
                child: Text(name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
              ),
              if (recommended) ...[
                const Icon(Icons.verified, size: 14, color: AppColors.primary),
                const SizedBox(width: 6),
              ],
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
            if (gerekce != null && gerekce.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(gerekce,
                  style: AppText.sans(
                      size: 10.5,
                      weight: FontWeight.w500,
                      color: AppColors.textSecondary)),
            ],
          ] else ...[
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _kv('Oranın iması', implied != null ? '%$implied' : '-'),
                Text('AI: analiz bekliyor',
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

  // ---------------- AI analiz butonu ve sonuç kartı ----------------

  /// Kredi maliyetli AI analiz butonu. Canlı maçta Altın olmayanlara kilit gösterir.
  Widget _analyzeButton({
    required String marketKey,
    required String label,
    required int cost,
    required bool isLive,
    required int tier,
    required bool hasResult,
    required int creditsLeft,
    bool compact = false,
  }) {
    final busy = _busy.contains(marketKey);
    if (isLive && tier < 3) {
      return SizedBox(
        width: compact ? null : double.infinity,
        child: OutlinedButton.icon(
          onPressed: () => showPaywall(context, highlightTier: 3),
          icon: const Icon(Icons.lock_outline, size: 15, color: AppColors.gold),
          label: Text('Canlı AI — Altın Paket',
              style: AppText.sans(
                  size: compact ? 11 : 12.5,
                  weight: FontWeight.w700,
                  color: AppColors.gold)),
        ),
      );
    }
    final text = busy
        ? 'Analiz ediliyor…'
        : hasResult
            ? (isLive ? '$label — Yenile ($cost kredi)' : label)
            : '$label ($cost kredi)';
    // Maç öncesi: sonuç varsa buton gizlenir (tekrar ücret yok, zaten açık)
    if (hasResult && !isLive && !busy) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment:
          compact ? CrossAxisAlignment.start : CrossAxisAlignment.center,
      children: [
        SizedBox(
          width: compact ? null : double.infinity,
          child: ElevatedButton.icon(
            onPressed: busy ? null : () => _analyzeMarket(marketKey),
            icon: busy
                ? const SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Color(0xFF0A1410)))
                : const Icon(Icons.auto_awesome, size: 16),
            label: Text(text, style: AppText.sans(size: compact ? 11.5 : 13, weight: FontWeight.w700)),
          ),
        ),
        if (!compact) ...[
          const SizedBox(height: 5),
          Text(
            'Kalan kredi: $creditsLeft · krediler her gün yenilenir'
            '${isLive ? ' · canlı analiz güncel skora göre yapılır' : ''}',
            style: AppText.sans(
                size: 10, weight: FontWeight.w500, color: AppColors.textMuted),
          ),
        ],
      ],
    );
  }

  /// Bir marketin AI analiz sonucu kartı: özet + tavsiye + seçenek olasılıkları
  /// + internet araştırması bulguları.
  Widget _aiResultCard(MarketAiAnalysis a, {bool showOptions = true}) {
    final tav = a.tavsiyeSecenek;
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AppColors.accentFaint,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: AppColors.accentDim),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.auto_awesome, size: 14, color: AppColors.primary),
              const SizedBox(width: 6),
              Expanded(
                child: Text('AI ANALİZ · ${a.marketLabel}'.toUpperCase(),
                    style: AppText.sans(
                        size: 10,
                        weight: FontWeight.w800,
                        color: AppColors.primary,
                        letterSpacing: 0.6)),
              ),
              if (a.isLive)
                Padding(
                  padding: const EdgeInsets.only(left: 6),
                  child: Text('CANLI',
                      style: AppText.mono(size: 9, color: AppColors.danger)),
                ),
              if (a.guven != null)
                Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: Text('Güven ${a.guven}/10',
                      style: AppText.mono(size: 10, color: AppColors.primary)),
                ),
            ],
          ),
          if (a.ozet != null && a.ozet!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(a.ozet!,
                style: AppText.sans(
                    size: 12, weight: FontWeight.w500, color: AppColors.textPrimary)),
          ],
          if (tav != null) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.oddCell,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.primary),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.verified, size: 13, color: AppColors.primary),
                  const SizedBox(width: 5),
                  Text('Tavsiye: ${tav.ad}',
                      style: AppText.sans(size: 11, weight: FontWeight.w800)),
                  if (tav.oran != null)
                    Text('  @${tav.oran!.toStringAsFixed(2)}',
                        style: AppText.mono(size: 11)),
                ],
              ),
            ),
          ],
          if (showOptions && a.secenekler.isNotEmpty) ...[
            const SizedBox(height: 10),
            ...a.secenekler.map(_optionRow),
          ],
          if (a.kaynaklar.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('İnternet araştırması:',
                style: AppText.sans(
                    size: 10, weight: FontWeight.w800, color: AppColors.textSecondary)),
            ...a.kaynaklar.map((k) => Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text('• $k',
                      style: AppText.sans(
                          size: 10,
                          weight: FontWeight.w500,
                          color: AppColors.textSecondary)),
                )),
          ],
          if (a.modelName != null) ...[
            const SizedBox(height: 7),
            Text('${a.provider ?? ''} · ${a.modelName}',
                style: AppText.sans(
                    size: 8.5, weight: FontWeight.w500, color: AppColors.textMuted)),
          ],
        ],
      ),
    );
  }

  Widget _optionRow(OptionAnalysis o) {
    final implied = o.impliedOlasilik?.round();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(o.ad,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.sans(size: 11.5, weight: FontWeight.w700)),
              ),
              if (o.degerVarMi && o.degerFarki != null) ...[
                ValueBadge(edge: o.degerFarki!.round()),
                const SizedBox(width: 7),
              ],
              if (o.oran != null)
                Text('@${o.oran!.toStringAsFixed(2)}', style: AppText.mono(size: 12)),
            ],
          ),
          if (o.olasilik != null) ...[
            const SizedBox(height: 5),
            ProbabilityBar(
              modelPct: o.olasilik!,
              impliedPct: implied,
              color: o.degerVarMi ? AppColors.primary : AppColors.primaryDark,
            ),
            const SizedBox(height: 3),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _kv('Model', '%${o.olasilik}'),
                _kv('Oranın iması', implied != null ? '%$implied' : '-'),
              ],
            ),
          ],
          if (o.gerekce != null && o.gerekce!.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(o.gerekce!,
                style: AppText.sans(
                    size: 10.5,
                    weight: FontWeight.w500,
                    color: AppColors.textSecondary)),
          ],
        ],
      ),
    );
  }

  // ---------------- Market grupları ----------------

  final Set<String> _openGroups = {'ana'}; // Ana Marketler varsayılan açık

  List<Widget> _groupedMarkets(
    MatchDetail d,
    Map<String, MarketAiAnalysis> analyses,
    bool isLive,
    int tier,
    int cost,
    int creditsLeft,
  ) {
    // Görünür marketleri gruplarına dağıt
    final byGroup = <String, List<BetMarket>>{};
    for (final m in d.markets) {
      final key = m.group ?? marketGroupKeyFor(m.name);
      (byGroup[key] ??= []).add(m);
    }

    final children = <Widget>[
      Text('MARKET GRUPLARI', style: AppText.section()),
      const SizedBox(height: 4),
      Text('Her marketin AI analizi ayrı kredi tüketir ($cost kredi/market).',
          style: AppText.sans(
              size: 10, weight: FontWeight.w500, color: AppColors.textMuted)),
      const SizedBox(height: 8),
    ];
    for (final g in d.marketGroups) {
      if (g.count == 0) continue;
      final isOpen = g.unlocked && _openGroups.contains(g.key);
      children.add(_groupHeader(g, isOpen));
      if (isOpen) {
        for (final m in (byGroup[g.key] ?? <BetMarket>[])) {
          children.add(_marketCard(m, analyses, isLive, tier, cost, creditsLeft));
        }
      }
      children.add(const SizedBox(height: 6));
    }
    return children;
  }

  Widget _groupHeader(MarketGroupInfo g, bool isOpen) {
    final unlocked = g.unlocked;
    return GestureDetector(
      onTap: () {
        if (!unlocked) {
          showPaywall(context, highlightTier: g.minTier.clamp(1, 3).toInt());
          return;
        }
        setState(() =>
            isOpen ? _openGroups.remove(g.key) : _openGroups.add(g.key));
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
              Text('${tierNames[g.minTier.clamp(0, 3).toInt()]} paketiyle',
                  style: AppText.sans(
                      size: 10.5, weight: FontWeight.w800, color: AppColors.gold)),
          ],
        ),
      ),
    );
  }

  /// Tek market kartı: oran kutuları + AI analiz butonu / sonucu.
  Widget _marketCard(
    BetMarket m,
    Map<String, MarketAiAnalysis> analyses,
    bool isLive,
    int tier,
    int cost,
    int creditsLeft,
  ) {
    final key = m.key;
    final result = key != null ? analyses[key] : null;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
            color: result != null ? AppColors.accentDim : AppColors.surface2),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(m.name,
                    style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
              ),
              if (key != null)
                _analyzeButton(
                  marketKey: key,
                  label: 'AI Analiz',
                  cost: cost,
                  isLive: isLive,
                  tier: tier,
                  hasResult: result != null,
                  creditsLeft: creditsLeft,
                  compact: true,
                ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 7,
            runSpacing: 7,
            children: m.outcomes.map<Widget>((o) {
              final opt = result?.optionFor(o.label);
              return ConstrainedBox(
                constraints: const BoxConstraints(minWidth: 74),
                child: OddsBox(
                  label: o.label,
                  value: o.odd,
                  compact: true,
                  aiPct: opt?.olasilik,
                  recommended: result != null && result.tavsiye == o.label,
                ),
              );
            }).toList(),
          ),
          if (result != null) ...[
            const SizedBox(height: 10),
            _aiResultCard(result),
          ],
        ],
      ),
    );
  }

  // ---------------- İstatistik bölümleri ----------------

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
