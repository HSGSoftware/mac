import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Takım amblemi. URL varsa ağdan yükler; yoksa veya yükleme başarısızsa
/// takımın baş harflerini içeren bir rozet gösterir (Nesine amblem
/// göndermediğinden amblemler TheSportsDB'den doldurulur, hepsi bulunmaz).
class TeamLogo extends StatelessWidget {
  final String? url;
  final String? name;
  final double size;
  const TeamLogo({super.key, this.url, this.name, this.size = 22});

  @override
  Widget build(BuildContext context) {
    final fallback = _fallback();
    if (url == null || url!.isEmpty) return fallback;
    return ClipRRect(
      borderRadius: BorderRadius.circular(size * 0.22),
      child: Image.network(
        url!,
        width: size,
        height: size,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) => fallback,
        loadingBuilder: (context, child, progress) =>
            progress == null ? child : fallback,
      ),
    );
  }

  Widget _fallback() {
    final n = (name ?? '').trim();
    final initials = n.isEmpty
        ? '?'
        : n
            .split(RegExp(r'\s+'))
            .where((e) => e.isNotEmpty)
            .take(2)
            .map((e) => e[0])
            .join()
            .toUpperCase();
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: AppColors.surface2,
        borderRadius: BorderRadius.circular(size * 0.22),
      ),
      alignment: Alignment.center,
      child: Text(
        initials,
        style: TextStyle(
          color: AppColors.textSecondary,
          fontSize: size * 0.42,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
