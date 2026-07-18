import 'dart:math';
import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Kazanma olasılığını gösteren dairesel gösterge.
class ProbabilityRing extends StatelessWidget {
  final int percent;
  final double size;
  const ProbabilityRing({super.key, required this.percent, this.size = 56});

  Color get _color {
    if (percent >= 65) return AppColors.primary;
    if (percent >= 45) return AppColors.gold;
    return AppColors.warning;
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _RingPainter(percent / 100, _color),
        child: Center(
          child: Text('%$percent',
              style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: size * 0.26,
                  color: _color)),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  final double fraction;
  final Color color;
  _RingPainter(this.fraction, this.color);

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.width * 0.11;
    final center = Offset(size.width / 2, size.height / 2);
    final radius = (size.width - stroke) / 2;

    final bg = Paint()
      ..color = AppColors.surface2
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    canvas.drawCircle(center, radius, bg);

    final fg = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round;
    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius),
      -pi / 2,
      2 * pi * fraction,
      false,
      fg,
    );
  }

  @override
  bool shouldRepaint(_RingPainter old) =>
      old.fraction != fraction || old.color != color;
}
