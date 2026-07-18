/// Günün Kuponu: modelin en yüksek değer marjlı seçimleri.
class CouponPick {
  final int matchId;
  final String league;
  final String? time;
  final String match;
  final String pickLabel; // 1 / X / 2
  final String pickName;
  final double odds;
  final int modelPct;
  final int edge;
  final String reason;

  CouponPick({
    required this.matchId,
    required this.league,
    this.time,
    required this.match,
    required this.pickLabel,
    required this.pickName,
    required this.odds,
    required this.modelPct,
    required this.edge,
    required this.reason,
  });

  factory CouponPick.fromJson(Map<String, dynamic> j) => CouponPick(
        matchId: j['match_id'] as int? ?? 0,
        league: j['league']?.toString() ?? '',
        time: j['time'] as String?,
        match: j['match']?.toString() ?? '',
        pickLabel: j['pick_label']?.toString() ?? '',
        pickName: j['pick_name']?.toString() ?? '',
        odds: (j['odds'] as num?)?.toDouble() ?? 0,
        modelPct: (j['model_pct'] as num?)?.toInt() ?? 0,
        edge: (j['edge'] as num?)?.toInt() ?? 0,
        reason: j['reason']?.toString() ?? '',
      );
}

class CouponSummary {
  final double? totalOdds;
  final double? modelPct;
  final double? impliedPct;
  final double? edge;
  final double? confidence;
  CouponSummary({
    this.totalOdds,
    this.modelPct,
    this.impliedPct,
    this.edge,
    this.confidence,
  });

  factory CouponSummary.fromJson(Map<String, dynamic> j) => CouponSummary(
        totalOdds: (j['total_odds'] as num?)?.toDouble(),
        modelPct: (j['model_pct'] as num?)?.toDouble(),
        impliedPct: (j['implied_pct'] as num?)?.toDouble(),
        edge: (j['edge'] as num?)?.toDouble(),
        confidence: (j['confidence'] as num?)?.toDouble(),
      );
}

class DailyCoupon {
  final String? date;
  final bool locked; // Altın paket gerektirir
  final List<CouponPick> picks;
  final CouponSummary summary;
  DailyCoupon({
    this.date,
    this.locked = false,
    required this.picks,
    required this.summary,
  });

  factory DailyCoupon.fromJson(Map<String, dynamic> j) => DailyCoupon(
        date: j['date'] as String?,
        locked: j['locked'] as bool? ?? false,
        picks: ((j['picks'] as List?) ?? [])
            .map((e) => CouponPick.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        summary: CouponSummary.fromJson(
            Map<String, dynamic>.from(j['summary'] is Map ? j['summary'] : {})),
      );
}
