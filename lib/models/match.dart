class League {
  final int? id;
  final String? name;
  final String? country;

  League({this.id, this.name, this.country});

  factory League.fromJson(Map<String, dynamic> j) => League(
        id: j['id'] as int?,
        name: j['name'] as String?,
        country: j['country'] as String?,
      );
}

class TeamSide {
  final String? name;
  final String? logo;
  TeamSide({this.name, this.logo});
  factory TeamSide.fromJson(Map<String, dynamic> j) =>
      TeamSide(name: j['name'] as String?, logo: j['logo'] as String?);
}

class MatchScore {
  final int home;
  final int away;
  final int? htHome;
  final int? htAway;
  MatchScore({required this.home, required this.away, this.htHome, this.htAway});
  factory MatchScore.fromJson(Map<String, dynamic> j) => MatchScore(
        home: j['home'] as int,
        away: j['away'] as int,
        htHome: j['ht_home'] as int?,
        htAway: j['ht_away'] as int?,
      );
}

/// Bültende bir maça ait model sinyali: en iyi MS seçimi + değer marjı.
class MatchSignal {
  final String pick; // MS1 / MSX / MS2
  final int? modelPct;
  final int? impliedPct;
  final int? edge;
  final bool hasValue;

  MatchSignal({
    required this.pick,
    this.modelPct,
    this.impliedPct,
    this.edge,
    this.hasValue = false,
  });

  factory MatchSignal.fromJson(Map<String, dynamic> j) => MatchSignal(
        pick: j['pick']?.toString() ?? 'MS1',
        modelPct: (j['model_pct'] as num?)?.toInt(),
        impliedPct: (j['implied_pct'] as num?)?.toInt(),
        edge: (j['edge'] as num?)?.toInt(),
        hasValue: j['has_value'] as bool? ?? false,
      );
}

class MatchItem {
  final int id;
  final String? iddaaCode;
  final String? startTime;
  final String status;
  final String? minute;
  final TeamSide home;
  final TeamSide away;
  final MatchScore? score;
  final Map<String, double?> odds;
  final bool hasAnalysis;
  final MatchSignal? signal;
  final League? league;

  MatchItem({
    required this.id,
    this.iddaaCode,
    this.startTime,
    required this.status,
    this.minute,
    required this.home,
    required this.away,
    this.score,
    required this.odds,
    required this.hasAnalysis,
    this.signal,
    this.league,
  });

  bool get isLive => status == 'live';

  factory MatchItem.fromJson(Map<String, dynamic> j) => MatchItem(
        id: j['id'] as int,
        iddaaCode: j['iddaa_code'] as String?,
        startTime: j['start_time'] as String?,
        status: j['status'] as String? ?? 'scheduled',
        minute: j['minute'] as String?,
        home: TeamSide.fromJson(Map<String, dynamic>.from(j['home'] ?? {})),
        away: TeamSide.fromJson(Map<String, dynamic>.from(j['away'] ?? {})),
        score: j['score'] != null
            ? MatchScore.fromJson(Map<String, dynamic>.from(j['score']))
            : null,
        odds: j['odds'] is Map
            ? (j['odds'] as Map).map(
                (k, v) => MapEntry(k.toString(), (v as num?)?.toDouble()),
              )
            : <String, double?>{},
        hasAnalysis: j['has_analysis'] as bool? ?? false,
        signal: j['signal'] is Map
            ? MatchSignal.fromJson(Map<String, dynamic>.from(j['signal']))
            : null,
        league: j['league'] != null
            ? League.fromJson(Map<String, dynamic>.from(j['league']))
            : null,
      );
}

/// Bülten: lige göre gruplanmış maçlar.
class LeagueGroup {
  final League league;
  final List<MatchItem> matches;
  LeagueGroup({required this.league, required this.matches});

  factory LeagueGroup.fromJson(Map<String, dynamic> j) => LeagueGroup(
        league: League.fromJson(Map<String, dynamic>.from(j['league'] ?? {})),
        matches: ((j['matches'] as List?) ?? [])
            .map((e) => MatchItem.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}
