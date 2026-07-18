/// Uygulama sabitleri.
class AppConfig {
  /// Backend API taban adresi.
  /// Codemagic/derleme sırasında geçersiz kılınabilir:
  ///   flutter build apk --dart-define=API_BASE_URL=https://alanadiniz.com/api/v1
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://webdigistore.com/macradar/api/v1',
  );
}

/// Bahis marketlerinin okunabilir Türkçe adları.
const Map<String, String> marketLabels = {
  'MS1': 'MS 1 (Ev)',
  'MSX': 'MS X (Beraberlik)',
  'MS2': 'MS 2 (Deplasman)',
  'CS1X': 'Çifte Şans 1-X',
  'CS12': 'Çifte Şans 1-2',
  'CSX2': 'Çifte Şans X-2',
  'ALT25': '2.5 Alt',
  'UST25': '2.5 Üst',
  'ALT15': '1.5 Alt',
  'UST15': '1.5 Üst',
  'ALT35': '3.5 Alt',
  'UST35': '3.5 Üst',
  'KGVAR': 'Karşılıklı Gol Var',
  'KGYOK': 'Karşılıklı Gol Yok',
  'IY1': 'İlk Yarı 1 (Ev)',
  'IYX': 'İlk Yarı X',
  'IY2': 'İlk Yarı 2 (Dep.)',
};

String marketLabel(String code) => marketLabels[code] ?? code;

/// MS market kodu → kısa etiket (1 / X / 2).
const Map<String, String> msShort = {
  'MS1': '1',
  'MSX': 'X',
  'MS2': '2',
};

// ---------------- Market grupları ve paketler ----------------

/// Paket adları (kademe sırasına göre).
const List<String> tierNames = ['Ücretsiz', 'Bronz', 'Gümüş', 'Altın'];

/// Bir market grubu tanımı: hangi pakette açılır.
class MarketGroupDef {
  final String key;
  final String name;
  final int tier; // bu grubu görebilmek için gereken minimum kademe
  const MarketGroupDef(this.key, this.name, this.tier);
}

const List<MarketGroupDef> marketGroupDefs = [
  MarketGroupDef('ana', 'Ana Marketler', 0),
  MarketGroupDef('gol', 'Gol Marketleri', 1),
  MarketGroupDef('handikap', 'Handikap & Kombine', 2),
  MarketGroupDef('ozel', 'Özel Marketler', 3),
];

MarketGroupDef marketGroupDef(String key) =>
    marketGroupDefs.firstWhere((g) => g.key == key,
        orElse: () => marketGroupDefs.last);

/// Market adına göre grup anahtarı.
String marketGroupKeyFor(String marketName) {
  final n = marketName.toLowerCase();
  if (n.contains('handikap') ||
      n.contains('maç sonucu ve') ||
      n.contains('y/ms')) {
    return 'handikap';
  }
  if (n.contains('gol')) return 'gol';
  if (n.contains('maç sonucu') ||
      n.contains('çifte şans') ||
      n.contains('yarı sonucu')) {
    return 'ana';
  }
  return 'ozel';
}

const Set<String> _anaCodes = {
  'MS1', 'MSX', 'MS2', 'CS1X', 'CS12', 'CSX2', 'IY1', 'IYX', 'IY2',
};

/// Analiz kaydı (kod veya market adı) için grup anahtarı.
String analysisGroupKeyFor(String marketCodeOrName) {
  if (_anaCodes.contains(marketCodeOrName)) return 'ana';
  if (marketCodeOrName.startsWith('ALT') ||
      marketCodeOrName.startsWith('UST') ||
      marketCodeOrName.startsWith('KG')) {
    return 'gol';
  }
  if (marketLabels.containsKey(marketCodeOrName)) return 'ana';
  return marketGroupKeyFor(marketCodeOrName);
}

/// MS market kodu → seçenek adı (ev/beraberlik/deplasman).
String outcomeName(String code, {String? home, String? away}) {
  switch (code) {
    case 'MS1':
      return home != null ? '$home kazanır' : 'Ev sahibi kazanır';
    case 'MSX':
      return 'Beraberlik';
    case 'MS2':
      return away != null ? '$away kazanır' : 'Deplasman kazanır';
    default:
      return marketLabel(code);
  }
}
