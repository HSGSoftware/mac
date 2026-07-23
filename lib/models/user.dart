class AppUser {
  final int id;
  final String email;
  final String? name;
  final String plan;
  final String? premiumUntil;
  final int dailyAnalysisCount;
  final int dailyCredits; // paketin günlük kredi hakkı (her gün sıfırlanır)
  final int creditsLeft; // bugün kalan kredi
  final int unreadNotifications; // okunmamış bildirim sayısı

  AppUser({
    required this.id,
    required this.email,
    this.name,
    required this.plan,
    this.premiumUntil,
    required this.dailyAnalysisCount,
    this.dailyCredits = 0,
    this.creditsLeft = 0,
    this.unreadNotifications = 0,
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
        dailyCredits: (j['daily_credits'] as num?)?.toInt() ?? 0,
        creditsLeft: (j['credits_left'] as num?)?.toInt() ?? 0,
        unreadNotifications: (j['unread_notifications'] as num?)?.toInt() ?? 0,
      );
}
