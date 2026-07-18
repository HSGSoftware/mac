class AppUser {
  final int id;
  final String email;
  final String? name;
  final String plan;
  final String? premiumUntil;
  final int dailyAnalysisCount;

  AppUser({
    required this.id,
    required this.email,
    this.name,
    required this.plan,
    this.premiumUntil,
    required this.dailyAnalysisCount,
  });

  bool get isPremium => plan == 'premium';

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: j['id'] as int,
        email: j['email'] as String,
        name: j['name'] as String?,
        plan: j['plan'] as String? ?? 'free',
        premiumUntil: j['premium_until'] as String?,
        dailyAnalysisCount: j['daily_analysis_count'] as int? ?? 0,
      );
}
