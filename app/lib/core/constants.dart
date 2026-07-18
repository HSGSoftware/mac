/// Uygulama sabitleri.
class AppConfig {
  /// Backend API taban adresi.
  /// Codemagic/derleme sırasında geçersiz kılınabilir:
  ///   flutter build apk --dart-define=API_BASE_URL=https://alanadiniz.com/api/v1
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://alanadiniz.com/api/v1',
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
};

String marketLabel(String code) => marketLabels[code] ?? code;
