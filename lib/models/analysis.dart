/// KREDİ sistemi modelleri: her market AYRI analiz edilir ve ayrı kredi tüketir.

/// Bir market analizindeki tek seçenek (AI olasılığı + değer bilgisi).
class OptionAnalysis {
  final String kod; // MS1/MSX/MS2 veya scraped seçenek adı
  final String ad;
  final double? oran;
  final int? olasilik; // AI olasılığı (0-100)
  final double? impliedOlasilik; // oranın ima ettiği olasılık
  final bool degerVarMi;
  final double? degerFarki;
  final String? gerekce;

  OptionAnalysis({
    required this.kod,
    required this.ad,
    this.oran,
    this.olasilik,
    this.impliedOlasilik,
    this.degerVarMi = false,
    this.degerFarki,
    this.gerekce,
  });

  factory OptionAnalysis.fromJson(Map<String, dynamic> j) => OptionAnalysis(
        kod: j['kod']?.toString() ?? '',
        ad: j['ad']?.toString() ?? j['kod']?.toString() ?? '?',
        oran: (j['oran'] as num?)?.toDouble(),
        olasilik: (j['olasilik'] as num?)?.toInt(),
        impliedOlasilik: (j['implied_olasilik'] as num?)?.toDouble(),
        degerVarMi: j['deger_var_mi'] as bool? ?? false,
        degerFarki: (j['deger_farki'] as num?)?.toDouble(),
        gerekce: j['gerekce'] as String?,
      );
}

/// Tek bir marketin AI analizi (ayrı AI çağrısı + internet araştırması).
class MarketAiAnalysis {
  final String marketKey; // 'MS' veya m_<hash>
  final String marketLabel;
  final bool isLive; // canlı maçta üretildi
  final List<OptionAnalysis> secenekler;
  final String? tavsiye; // önerilen seçeneğin kodu
  final int? guven; // 1-10
  final String? ozet;
  final List<String> kaynaklar; // internet araştırması bulguları
  final String? provider;
  final String? modelName;
  final String? createdAt;

  MarketAiAnalysis({
    required this.marketKey,
    required this.marketLabel,
    this.isLive = false,
    required this.secenekler,
    this.tavsiye,
    this.guven,
    this.ozet,
    this.kaynaklar = const [],
    this.provider,
    this.modelName,
    this.createdAt,
  });

  OptionAnalysis? optionFor(String kod) {
    for (final o in secenekler) {
      if (o.kod == kod) return o;
    }
    return null;
  }

  /// Önerilen seçenek (tavsiye koduna göre).
  OptionAnalysis? get tavsiyeSecenek =>
      tavsiye != null ? optionFor(tavsiye!) : null;

  factory MarketAiAnalysis.fromJson(Map<String, dynamic> j) => MarketAiAnalysis(
        marketKey: j['market_key']?.toString() ?? '',
        marketLabel: j['market_label']?.toString() ?? 'Market',
        isLive: j['is_live'] as bool? ?? false,
        secenekler: ((j['secenekler'] as List?) ?? [])
            .whereType<Map>()
            .map((e) => OptionAnalysis.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        tavsiye: j['tavsiye']?.toString(),
        guven: (j['guven'] as num?)?.toInt(),
        ozet: j['ozet'] as String?,
        kaynaklar: ((j['kaynaklar'] as List?) ?? [])
            .map((e) => e.toString())
            .toList(),
        provider: j['provider'] as String?,
        modelName: j['model_name'] as String?,
        createdAt: j['created_at'] as String?,
      );
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
  final String? key; // sunucunun verdiği analiz anahtarı (m_<hash>)
  final String? group; // grup anahtarı (ana/gol/handikap/ozel)
  final List<MarketOutcome> outcomes;
  BetMarket({
    required this.name,
    this.line,
    this.key,
    this.group,
    required this.outcomes,
  });

  factory BetMarket.fromJson(Map<String, dynamic> j) => BetMarket(
        name: j['ad']?.toString() ?? 'Market',
        line: (j['sov'] as num?)?.toDouble(),
        key: j['key']?.toString(),
        group: j['grup']?.toString(),
        outcomes: ((j['secenekler'] as List?) ?? [])
            .whereType<Map>()
            .map((e) => MarketOutcome.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}

/// Bir oran grubunun görünürlük durumu (minimum paket sunucudan gelir;
/// admin panelinden ayarlanır).
class MarketGroupInfo {
  final String key; // ana / gol / handikap / ozel
  final String name;
  final int minTier; // görebilmek için gereken minimum paket kademesi
  final bool unlocked;
  final int count;

  MarketGroupInfo({
    required this.key,
    required this.name,
    required this.minTier,
    required this.unlocked,
    required this.count,
  });

  factory MarketGroupInfo.fromJson(Map<String, dynamic> j) => MarketGroupInfo(
        key: j['key']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        minTier: (j['min_tier'] as num?)?.toInt() ?? 0,
        unlocked: j['unlocked'] as bool? ?? false,
        count: (j['count'] as num?)?.toInt() ?? 0,
      );
}

/// Maç detay yanıtı: maç + oranlar + görünür marketler + grup durumu +
/// istatistikler + kullanıcının kredi ile açtığı market analizleri.
class MatchDetail {
  final Map<String, dynamic> match;
  final Map<String, double> odds;
  final List<BetMarket> markets; // paketin görebildiği grupların marketleri
  final List<MarketGroupInfo> marketGroups;
  final Map<String, dynamic> stats;
  final List<MarketAiAnalysis> marketAnalyses; // kullanıcının açtıkları
  final int creditCostMarket; // bir market analizinin kredi maliyeti
  final int creditCostLiveMarket; // canlı maçta (yalnız Altın)
  final int? creditsLeft; // giriş yapan kullanıcının kalan günlük kredisi

  MatchDetail({
    required this.match,
    required this.odds,
    required this.markets,
    this.marketGroups = const [],
    required this.stats,
    this.marketAnalyses = const [],
    this.creditCostMarket = 1,
    this.creditCostLiveMarket = 2,
    this.creditsLeft,
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
    final groups = <MarketGroupInfo>[];
    if (j['market_groups'] is List) {
      for (final g in (j['market_groups'] as List)) {
        if (g is Map) {
          groups.add(MarketGroupInfo.fromJson(Map<String, dynamic>.from(g)));
        }
      }
    }
    final analyses = <MarketAiAnalysis>[];
    if (j['market_analyses'] is List) {
      for (final a in (j['market_analyses'] as List)) {
        if (a is Map) {
          analyses.add(MarketAiAnalysis.fromJson(Map<String, dynamic>.from(a)));
        }
      }
    }
    final costs = j['credit_costs'] is Map
        ? Map<String, dynamic>.from(j['credit_costs'])
        : <String, dynamic>{};
    return MatchDetail(
      match: j['match'] is Map ? Map<String, dynamic>.from(j['match']) : {},
      odds: odds,
      markets: markets,
      marketGroups: groups,
      stats: j['stats'] is Map ? Map<String, dynamic>.from(j['stats']) : {},
      marketAnalyses: analyses,
      creditCostMarket: (costs['market'] as num?)?.toInt() ?? 1,
      creditCostLiveMarket: (costs['live_market'] as num?)?.toInt() ?? 2,
      creditsLeft: (j['credits_left'] as num?)?.toInt(),
    );
  }
}
