import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Tek bir oran kutusu (etiket + değer). Bülten ve maç detayında kullanılır.
class OddsBox extends StatelessWidget {
  final String label;
  final double? value;
  final bool highlight;
  final bool compact;
  const OddsBox({
    super.key,
    required this.label,
    required this.value,
    this.highlight = false,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    final has = value != null;
    return Container(
      padding: EdgeInsets.symmetric(
          vertical: compact ? 6 : 9, horizontal: compact ? 6 : 10),
      decoration: BoxDecoration(
        color: highlight
            ? AppColors.primary.withValues(alpha: 0.14)
            : AppColors.surface2,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: highlight ? AppColors.primary : Colors.transparent,
          width: 1,
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: TextStyle(
              color: AppColors.textSecondary,
              fontSize: compact ? 9 : 10,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.3,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            has ? value!.toStringAsFixed(2) : '-',
            style: TextStyle(
              color: has
                  ? (highlight ? AppColors.primary : AppColors.textPrimary)
                  : AppColors.textSecondary,
              fontSize: compact ? 12 : 14,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}
