import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// MaçRadar renk paleti ve tema.
/// "Maç Analiz" tasarımına göre: çok koyu lacivert zemin, taze yeşil vurgu,
/// premium için altın, canlı için kırmızı; sayılar için mono font.
class AppColors {
  // Zeminler
  static const bg = Color(0xFF080C12);
  static const bg2 = Color(0xFF0B1017);
  static const surface = Color(0xFF13181E); // kart yüzeyi
  static const surface2 = Color(0xFF20262D); // kenarlık / ayraç
  static const cardTop = Color(0xFF161C23);
  static const cardBot = Color(0xFF11161C);
  static const oddCell = Color(0xFF191F25);
  static const oddBorder = Color(0xFF262C33);

  // Vurgular
  static const primary = Color(0xFF7CDF81); // model / kazanç yeşili
  static const primaryDark = Color(0xFF4FB874);
  static const accent = Color(0xFF7CDF81);
  static const blue = Color(0xFF5480C7); // deplasman (istatistik barları)
  static const gold = Color(0xFFF1C45E); // premium / değer
  static const gold2 = Color(0xFFD49648);
  static const warning = Color(0xFFF1C45E);
  static const danger = Color(0xFFFF5251); // canlı / kayıp
  static const dangerSoft = Color(0xFFFB9890);

  // Metin
  static const textPrimary = Color(0xFFE9EBEE);
  static const textSecondary = Color(0xFF7A8189);
  static const textMuted = Color(0xFF5E646C);

  static const primaryGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF7CDF81), Color(0xFF4FB874)],
  );

  static const goldGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFF1C45E), Color(0xFFD49648)],
  );

  static const cardGradient = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [cardTop, cardBot],
  );

  static const headerGradient = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [Color(0xFF10161D), Color(0xFF0A0F15)],
  );

  /// Yeşilin saydam tonu (DEĞER rozeti zemini)
  static Color get accentDim => primary.withValues(alpha: 0.16);
  static Color get accentFaint => primary.withValues(alpha: 0.07);
  static Color get goldDim => gold.withValues(alpha: 0.16);
}

/// Yazı tipi yardımcıları. Sayılar/oranlar için JetBrains Mono, geneli Archivo.
class AppText {
  static TextStyle mono({
    double size = 13,
    FontWeight weight = FontWeight.w700,
    Color? color,
    double? letterSpacing,
  }) =>
      GoogleFonts.jetBrainsMono(
        fontSize: size,
        fontWeight: weight,
        color: color ?? AppColors.textPrimary,
        letterSpacing: letterSpacing,
      );

  static TextStyle sans({
    double size = 14,
    FontWeight weight = FontWeight.w600,
    Color? color,
    double letterSpacing = -0.01,
  }) =>
      GoogleFonts.archivo(
        fontSize: size,
        fontWeight: weight,
        color: color ?? AppColors.textPrimary,
        letterSpacing: letterSpacing,
      );

  /// Bölüm başlığı: küçük, kalın, harf aralıklı, büyük harf.
  static TextStyle section() => GoogleFonts.archivo(
        fontSize: 10.5,
        fontWeight: FontWeight.w800,
        letterSpacing: 1.0,
        color: AppColors.textSecondary,
      );
}

class AppTheme {
  static ThemeData get dark {
    final base = ThemeData.dark(useMaterial3: true);
    final textTheme = GoogleFonts.archivoTextTheme(base.textTheme).apply(
      bodyColor: AppColors.textPrimary,
      displayColor: AppColors.textPrimary,
    );
    return base.copyWith(
      scaffoldBackgroundColor: AppColors.bg,
      colorScheme: base.colorScheme.copyWith(
        primary: AppColors.primary,
        secondary: AppColors.accent,
        surface: AppColors.surface,
        error: AppColors.danger,
        onPrimary: const Color(0xFF0A1410),
      ),
      textTheme: textTheme,
      cardTheme: CardThemeData(
        color: AppColors.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: AppColors.surface2),
        ),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: GoogleFonts.archivo(
          color: AppColors.textPrimary,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.3,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: const Color(0xFF0A1410),
          textStyle: GoogleFonts.archivo(fontWeight: FontWeight.w800, fontSize: 14.5),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
          elevation: 0,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.surface2),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.surface2),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.4),
        ),
        labelStyle: const TextStyle(color: AppColors.textSecondary),
      ),
      dividerColor: AppColors.surface2,
    );
  }
}
