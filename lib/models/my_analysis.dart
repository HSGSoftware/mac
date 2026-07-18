/// "Analizlerim" ekranındaki tek kayıt.
class MyAnalysisItem {
  final int matchId;
  final String? league;
  final String match;
  final String? date;
  final bool hasAnalysis;
  final String? pick; // MS1/MSX/MS2
  final int? modelPct;
  final double? odds;
  final String status; // won / lost / open
  final String? score;

  MyAnalysisItem({
    required this.matchId,
    this.league,
    required this.match,
    this.date,
    required this.hasAnalysis,
    this.pick,
    this.modelPct,
    this.odds,
    required this.status,
    this.score,
  });

  factory MyAnalysisItem.fromJson(Map<String, dynamic> j) => MyAnalysisItem(
        matchId: j['match_id'] as int? ?? 0,
        league: j['league'] as String?,
        match: j['match']?.toString() ?? '',
        date: j['date'] as String?,
        hasAnalysis: j['has_analysis'] as bool? ?? false,
        pick: j['pick'] as String?,
        modelPct: (j['model_pct'] as num?)?.toInt(),
        odds: (j['odds'] as num?)?.toDouble(),
        status: j['status']?.toString() ?? 'open',
        score: j['score'] as String?,
      );
}

class MyAnalysesResponse {
  final List<MyAnalysisItem> items;
  final int count;
  final int? hitPct;
  final double? avgOdds;
  MyAnalysesResponse({
    required this.items,
    required this.count,
    this.hitPct,
    this.avgOdds,
  });

  factory MyAnalysesResponse.fromJson(Map<String, dynamic> j) {
    final stats = Map<String, dynamic>.from(j['stats'] ?? {});
    return MyAnalysesResponse(
      items: ((j['items'] as List?) ?? [])
          .map((e) => MyAnalysisItem.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      count: (stats['count'] as num?)?.toInt() ?? 0,
      hitPct: (stats['hit_pct'] as num?)?.toInt(),
      avgOdds: (stats['avg_odds'] as num?)?.toDouble(),
    );
  }
}
