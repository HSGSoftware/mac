/// Maç detayındaki `stats` haritasını (form / H2H / sezon karşılaştırması)
/// esnek biçimde ayrıştırır. Veri kazınmadıysa listeler boş döner ve
/// ilgili bölümler UI'da gizlenir.
class TeamForm {
  final List<String> results; // ['G','G','B','M','G'] (Galibiyet/Beraberlik/Mağlubiyet)
  final String? note;
  TeamForm({required this.results, this.note});

  bool get isEmpty => results.isEmpty;

  static TeamForm parse(dynamic raw) {
    if (raw == null) return TeamForm(results: const []);
    List<String> res = [];
    String? note;
    if (raw is List) {
      res = raw.map(_letter).where((e) => e.isNotEmpty).toList();
    } else if (raw is Map) {
      final list = raw['results'] ?? raw['form'] ?? raw['son5'];
      if (list is List) res = list.map(_letter).where((e) => e.isNotEmpty).toList();
      note = (raw['note'] ?? raw['not'])?.toString();
    } else if (raw is String) {
      res = raw.split('').map(_letter).where((e) => e.isNotEmpty).toList();
    }
    return TeamForm(results: res.take(5).toList(), note: note);
  }

  static String _letter(dynamic v) {
    final s = v is Map ? (v['result'] ?? v['r'] ?? v['l'] ?? '').toString() : v.toString();
    final u = s.trim().toUpperCase();
    if (u.startsWith('G') || u.startsWith('W') || u == '1') return 'G';
    if (u.startsWith('B') || u.startsWith('D') || u == 'X') return 'B';
    if (u.startsWith('M') || u.startsWith('L') || u == '2') return 'M';
    return '';
  }
}

class H2hItem {
  final String date;
  final String fixture;
  final String score;
  final String win; // 'h' | 'a' | 'd'
  H2hItem({required this.date, required this.fixture, required this.score, this.win = 'd'});

  static List<H2hItem> parseList(dynamic raw) {
    if (raw is! List) return const [];
    return raw.whereType<Map>().map((e) {
      final m = Map<String, dynamic>.from(e);
      return H2hItem(
        date: (m['date'] ?? m['tarih'] ?? '').toString(),
        fixture: (m['fixture'] ?? m['mac'] ?? m['match'] ?? '').toString(),
        score: (m['score'] ?? m['skor'] ?? '').toString(),
        win: (m['win'] ?? m['kazanan'] ?? 'd').toString(),
      );
    }).toList();
  }
}

class ComparisonStat {
  final String name;
  final double home;
  final double away;
  final double max;
  ComparisonStat({required this.name, required this.home, required this.away, this.max = 100});

  static double _d(dynamic v) {
    if (v is num) return v.toDouble();
    if (v is String) return double.tryParse(v.replaceAll(',', '.')) ?? 0;
    return 0;
  }

  static List<ComparisonStat> parseList(dynamic raw) {
    if (raw is! List) return const [];
    return raw.whereType<Map>().map((e) {
      final m = Map<String, dynamic>.from(e);
      return ComparisonStat(
        name: (m['name'] ?? m['ad'] ?? '').toString(),
        home: _d(m['h'] ?? m['home'] ?? m['ev']),
        away: _d(m['a'] ?? m['away'] ?? m['dep']),
        max: m['max'] != null ? _d(m['max']) : 100,
      );
    }).toList();
  }
}

/// Detaydaki `stats` haritasının ayrıştırılmış hali.
class MatchStats {
  final TeamForm formHome;
  final TeamForm formAway;
  final List<H2hItem> h2h;
  final List<ComparisonStat> season;
  final List<ComparisonStat> live;

  MatchStats({
    required this.formHome,
    required this.formAway,
    required this.h2h,
    required this.season,
    required this.live,
  });

  bool get hasAny =>
      !formHome.isEmpty ||
      !formAway.isEmpty ||
      h2h.isNotEmpty ||
      season.isNotEmpty ||
      live.isNotEmpty;

  factory MatchStats.fromMap(Map<String, dynamic> s) => MatchStats(
        formHome: TeamForm.parse(s['form_home']),
        formAway: TeamForm.parse(s['form_away']),
        h2h: H2hItem.parseList(s['h2h']),
        season: ComparisonStat.parseList(s['season'] ?? s['comparison'] ?? s['standings']),
        live: ComparisonStat.parseList(s['live'] ?? s['live_stats']),
      );
}
