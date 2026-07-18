class MarketAnalysis {
  final String market;
  final double? oran;
  final int? olasilik;
  final double? impliedOlasilik;
  final bool degerVarMi;
  final double? degerFarki;
  final String? gerekce;

  MarketAnalysis({
    required this.market,
    this.oran,
    this.olasilik,
    this.impliedOlasilik,
    this.degerVarMi = false,
    this.degerFarki,
    this.gerekce,
  });

  factory MarketAnalysis.fromJson(Map<String, dynamic> j) => MarketAnalysis(
        market: j['market'] as String? ?? '',
        oran: (j['oran'] as num?)?.toDouble(),
        olasilik: (j['olasilik'] as num?)?.toInt(),
        impliedOlasilik: (j['implied_olasilik'] as num?)?.toDouble(),
        degerVarMi: j['deger_var_mi'] as bool? ?? false,
        degerFarki: (j['deger_farki'] as num?)?.toDouble(),
        gerekce: j['gerekce'] as String?,
      );
}

/// Modelin gerekçe maddesi ("Model neden böyle düşünüyor?").
class AnalysisReason {
  final String tag;
  final String text;
  AnalysisReason({required this.tag, required this.text});

  factory AnalysisReason.fromJson(Map<String, dynamic> j) => AnalysisReason(
        tag: (j['etiket'] ?? j['tag'] ?? '').toString(),
        text: (j['metin'] ?? j['text'] ?? '').toString(),
      );
}

class Analysis {
  final int id;
  final int matchId;
  final String provider;
  final String? modelName;
  final List<MarketAnalysis> markets;
  final String? generalNote;
  final String? safestPick;
  final String? surpriseLevel;
  final bool isRisky;
  final int? confidence; // 1-10 güven
  final List<AnalysisReason> reasons;
  final String? createdAt;

  Analysis({
    required this.id,
    required this.matchId,
    required this.provider,
    this.modelName,
    required this.markets,
    this.generalNote,
    this.safestPick,
    this.surpriseLevel,
    required this.isRisky,
    this.confidence,
    this.reasons = const [],
    this.createdAt,
  });

  /// Belirli bir MS market için model olasılığı (0-100).
  MarketAnalysis? marketFor(String code) {
    for (final m in markets) {
      if (m.market == code) return m;
    }
    return null;
  }

  factory Analysis.fromJson(Map<String, dynamic> j) {
    final result = j['result'] as Map<String, dynamic>?;
    final markets = ((result?['markets'] as List?) ?? [])
        .map((e) => MarketAnalysis.fromJson(Map<String, dynamic>.from(e)))
        .toList();
    final reasons = ((result?['nedenler'] as List?) ?? [])
        .whereType<Map>()
        .map((e) => AnalysisReason.fromJson(Map<String, dynamic>.from(e)))
        .toList();
    return Analysis(
      id: j['id'] as int,
      matchId: j['match_id'] as int? ?? 0,
      provider: j['provider'] as String? ?? '',
      modelName: j['model_name'] as String?,
      markets: markets,
      generalNote: j['general_note'] as String?,
      safestPick: j['safest_pick'] as String?,
      surpriseLevel: j['surprise_level'] as String?,
      isRisky: j['is_risky'] as bool? ?? false,
      confidence: (result?['guven'] as num?)?.toInt(),
      reasons: reasons,
      createdAt: j['created_at'] as String?,
    );
  }
}

/// Bir bahis marketinin tek seçeneği (ör. "Alt" => 1.80).
class MarketOutcome {
  final String label;
  final double odd;
  MarketOutcome({required this.label, required this.odd});

  factory MarketOutcome.fromJson(Map<String, dynamic> j) => MarketOutcome(
        label: j['ad']?.toString() ?? '?',
        odd: (j['oran'] as num?)?.toDouble() ?? 0,
      );
}

/// Tam bir bahis marketi (ör. "2,5 Gol Alt/Üst" + seçenekleri).
class BetMarket {
  final String name;
  final double? line; // gol çizgisi (SOV), varsa
  final List<MarketOutcome> outcomes;
  BetMarket({required this.name, this.line, required this.outcomes});

  factory BetMarket.fromJson(Map<String, dynamic> j) => BetMarket(
        name: j['ad']?.toString() ?? 'Market',
        line: (j['sov'] as num?)?.toDouble(),
        outcomes: ((j['secenekler'] as List?) ?? [])
            .whereType<Map>()
            .map((e) => MarketOutcome.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}

/// Maç detay yanıtı: maç + oranlar + tüm marketler + istatistikler + (varsa) analiz.
class MatchDetail {
  final Map<String, dynamic> match;
  final Map<String, double> odds;
  final List<BetMarket> markets;
  final Map<String, dynamic> stats;
  final Analysis? analysis;

  MatchDetail({
    required this.match,
    required this.odds,
    required this.markets,
    required this.stats,
    this.analysis,
  });

  factory MatchDetail.fromJson(Map<String, dynamic> j) {
    final odds = <String, double>{};
    if (j['odds'] is Map) {
      (j['odds'] as Map).forEach((k, v) {
        if (v is num) odds[k.toString()] = v.toDouble();
      });
    }
    final markets = <BetMarket>[];
    if (j['markets'] is List) {
      for (final m in (j['markets'] as List)) {
        if (m is Map) {
          markets.add(BetMarket.fromJson(Map<String, dynamic>.from(m)));
        }
      }
    }
    return MatchDetail(
      match: j['match'] is Map ? Map<String, dynamic>.from(j['match']) : {},
      odds: odds,
      markets: markets,
      stats: j['stats'] is Map ? Map<String, dynamic>.from(j['stats']) : {},
      analysis: j['analysis'] is Map
          ? Analysis.fromJson(Map<String, dynamic>.from(j['analysis']))
          : null,
    );
  }
}
