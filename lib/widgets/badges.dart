import 'package:flutter/material.dart';

import '../core/theme.dart';

/// "DEĞER" rozeti (isteğe bağlı +marj yüzdesi).
class ValueBadge extends StatelessWidget {
  final int? edge;
  const ValueBadge({super.key, this.edge});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
      decoration: BoxDecoration(
        color: AppColors.accentDim,
        borderRadius: BorderRadius.circular(5),
      ),
      child: Text(
        edge != null ? 'DEĞER +$edge%' : 'DEĞER',
        style: AppText.sans(
          size: 9,
          weight: FontWeight.w800,
          color: AppColors.primary,
          letterSpacing: 0.7,
        ),
      ),
    );
  }
}

/// PREMIUM altın rozeti.
class PremiumBadge extends StatelessWidget {
  const PremiumBadge({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        gradient: AppColors.goldGradient,
        borderRadius: BorderRadius.circular(5),
      ),
      child: Text(
        'PREMIUM',
        style: AppText.sans(
          size: 9,
          weight: FontWeight.w800,
          color: const Color(0xFF2A2008),
          letterSpacing: 0.9,
        ),
      ),
    );
  }
}

/// Canlı dakika rozeti (yanıp sönen kırmızı nokta + dakika).
class LiveMinute extends StatelessWidget {
  final String? minute;
  const LiveMinute({super.key, this.minute});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const _PulseDot(),
        const SizedBox(width: 4),
        Text(
          minute != null && minute!.isNotEmpty ? "$minute'" : 'CANLI',
          style: AppText.mono(size: 11, color: AppColors.danger),
        ),
      ],
    );
  }
}

class _PulseDot extends StatefulWidget {
  const _PulseDot();
  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween(begin: 1.0, end: 0.35).animate(_c),
      child: Container(
        width: 7,
        height: 7,
        decoration: const BoxDecoration(
          color: AppColors.danger,
          shape: BoxShape.circle,
        ),
      ),
    );
  }
}

/// Genel amaçlı küçük etiket pili.
class Pill extends StatelessWidget {
  final String text;
  final Color color;
  final Color? bg;
  const Pill({super.key, required this.text, required this.color, this.bg});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3.5),
      decoration: BoxDecoration(
        color: bg ?? color.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        text,
        style: AppText.sans(
            size: 9.5, weight: FontWeight.w800, color: color, letterSpacing: 0.6),
      ),
    );
  }
}
