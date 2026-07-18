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
    this.createdAt,
  });

  factory Analysis.fromJson(Map<String, dynamic> j) {
    final result = j['result'] as Map<String, dynamic>?;
    final markets = ((result?['markets'] as List?) ?? [])
        .map((e) => MarketAnalysis.fromJson(Map<String, dynamic>.from(e)))
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
      createdAt: j['created_at'] as String?,
    );
  }
}

/// Maç detay yanıtı: maç + oranlar + istatistikler + (varsa) analiz.
class MatchDetail {
  final Map<String, dynamic> match;
  final Map<String, double> odds;
  final Map<String, dynamic> stats;
  final Analysis? analysis;

  MatchDetail({
    required this.match,
    required this.odds,
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
    return MatchDetail(
      match: j['match'] is Map ? Map<String, dynamic>.from(j['match']) : {},
      odds: odds,
      stats: j['stats'] is Map ? Map<String, dynamic>.from(j['stats']) : {},
      analysis: j['analysis'] is Map
          ? Analysis.fromJson(Map<String, dynamic>.from(j['analysis']))
          : null,
    );
  }
}
