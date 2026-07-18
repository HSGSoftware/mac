class AppUser {
  final int id;
  final String email;
  final String? name;
  final String plan;
  final String? premiumUntil;
  final int dailyAnalysisCount;
  final int dailyTokens; // paketin günlük token hakkı (her gün sıfırlanır)
  final int tokensLeft; // bugün kalan token

  AppUser({
    required this.id,
    required this.email,
    this.name,
    required this.plan,
    this.premiumUntil,
    required this.dailyAnalysisCount,
    this.dailyTokens = 0,
    this.tokensLeft = 0,
  });

  /// Paket kademesi: 0=Ücretsiz, 1=Bronz, 2=Gümüş, 3=Altın.
  /// Eski 'premium' değeri Altın sayılır.
  int get tier {
    switch (plan) {
      case 'bronz':
        return 1;
      case 'gumus':
        return 2;
      case 'altin':
      case 'premium':
        return 3;
      default:
        return 0;
    }
  }

  bool get isPremium => tier > 0;

  String get planName =>
      const ['ÜCRETSİZ', 'BRONZ', 'GÜMÜŞ', 'ALTIN'][tier];

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: j['id'] as int,
        email: j['email'] as String,
        name: j['name'] as String?,
        plan: j['plan'] as String? ?? 'free',
        premiumUntil: j['premium_until'] as String?,
        dailyAnalysisCount: j['daily_analysis_count'] as int? ?? 0,
        dailyTokens: (j['daily_tokens'] as num?)?.toInt() ?? 0,
        tokensLeft: (j['tokens_left'] as num?)?.toInt() ?? 0,
      );
}
