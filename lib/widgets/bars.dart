import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Model olasılığı barı + oranın ima ettiği olasılık işareti (beyaz çizgi).
/// Tasarımdaki "Kazanma olasılıkları" görselini yeniden üretir.
class ProbabilityBar extends StatelessWidget {
  final int modelPct; // 0-100
  final int? impliedPct; // 0-100 (beyaz işaret)
  final Color color;
  const ProbabilityBar({
    super.key,
    required this.modelPct,
    this.impliedPct,
    this.color = AppColors.primary,
  });

  @override
  Widget build(BuildContext context) {
    final m = (modelPct.clamp(0, 100)) / 100;
    final imp = impliedPct == null ? null : impliedPct!.clamp(0, 100) / 100;
    return LayoutBuilder(
      builder: (context, c) {
        final w = c.maxWidth;
        final markerLeft = imp == null ? 0.0 : (w * imp - 1).clamp(0.0, w - 2);
        return SizedBox(
          height: 20,
          width: w,
          child: Stack(
            children: [
              Container(
                width: w,
                height: 20,
                decoration: BoxDecoration(
                  color: const Color(0xFF1D2228),
                  borderRadius: BorderRadius.circular(6),
                ),
              ),
              Container(
                width: (w * m).toDouble(),
                height: 20,
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: BorderRadius.circular(6),
                ),
              ),
              if (imp != null)
                Positioned(
                  left: markerLeft.toDouble(),
                  top: 0,
                  child: Container(width: 2, height: 20, color: Colors.white),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// Ev-Deplasman karşılaştırma barı (ortadan iki yana dolan).
class ComparisonBar extends StatelessWidget {
  final String name;
  final double home;
  final double away;
  final double max;
  const ComparisonBar({
    super.key,
    required this.name,
    required this.home,
    required this.away,
    this.max = 100,
  });

  String _fmt(double v) =>
      v == v.roundToDouble() ? v.toInt().toString() : v.toStringAsFixed(1);

  @override
  Widget build(BuildContext context) {
    final safeMax = max <= 0 ? 100.0 : max;
    final hp = (home / safeMax).clamp(0.0, 1.0);
    final ap = (away / safeMax).clamp(0.0, 1.0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Row(
            children: [
              Text(_fmt(home), style: AppText.mono(size: 11)),
              Expanded(
                child: Text(name,
                    textAlign: TextAlign.center,
                    style: AppText.sans(
                        size: 10.5,
                        weight: FontWeight.w500,
                        color: AppColors.textSecondary)),
              ),
              Text(_fmt(away), style: AppText.mono(size: 11)),
            ],
          ),
        ),
        LayoutBuilder(
          builder: (context, c) {
            final half = (c.maxWidth - 3) / 2;
            return Row(
              children: [
                SizedBox(
                  width: half,
                  height: 6,
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: Container(
                      width: (half * hp).toDouble(),
                      height: 6,
                      decoration: BoxDecoration(
                        color: AppColors.primary,
                        borderRadius: BorderRadius.circular(3),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 3),
                SizedBox(
                  width: half,
                  height: 6,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Container(
                      width: (half * ap).toDouble(),
                      height: 6,
                      decoration: BoxDecoration(
                        color: AppColors.blue,
                        borderRadius: BorderRadius.circular(3),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ],
    );
  }
}

/// Form kutusu: G/B/M harfi renkli.
class FormChip extends StatelessWidget {
  final String letter;
  const FormChip({super.key, required this.letter});

  @override
  Widget build(BuildContext context) {
    Color bg, fg;
    switch (letter) {
      case 'G':
        bg = AppColors.accentDim;
        fg = AppColors.primary;
        break;
      case 'M':
        bg = AppColors.danger.withValues(alpha: 0.22);
        fg = AppColors.dangerSoft;
        break;
      default: // B
        bg = const Color(0xFF262C33);
        fg = AppColors.textPrimary;
    }
    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(7),
      ),
      child: Text(letter, style: AppText.mono(size: 11, color: fg)),
    );
  }
}
