import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Tek bir oran kutusu (etiket + değer). Bülten ve maç detayında kullanılır.
///
/// [aiPct] verilirse oranın altında AI olasılığı (%NN) gösterilir.
/// [recommended] true ise "oynanması önerilen" seçenek olarak vurgulanır
/// (yeşil çerçeve + ✓ rozet). [highlight] genel vurgulama içindir.
class OddsBox extends StatelessWidget {
  final String label;
  final double? value;
  final bool highlight;
  final bool compact;
  final int? aiPct; // AI olasılığı (0-100), oranın altında gösterilir
  final bool recommended; // AI'nin önerdiği (tavsiye) seçenek
  const OddsBox({
    super.key,
    required this.label,
    required this.value,
    this.highlight = false,
    this.compact = false,
    this.aiPct,
    this.recommended = false,
  });

  @override
  Widget build(BuildContext context) {
    final has = value != null;
    final marked = highlight || recommended;
    final box = Container(
      padding: EdgeInsets.symmetric(
          vertical: compact ? 6 : 9, horizontal: compact ? 6 : 10),
      decoration: BoxDecoration(
        color: marked
            ? AppColors.primary.withValues(alpha: 0.14)
            : AppColors.surface2,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: marked ? AppColors.primary : Colors.transparent,
          width: recommended ? 1.5 : 1,
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
                  ? (marked ? AppColors.primary : AppColors.textPrimary)
                  : AppColors.textSecondary,
              fontSize: compact ? 12 : 14,
              fontWeight: FontWeight.w700,
            ),
          ),
          if (aiPct != null) ...[
            const SizedBox(height: 2),
            Text(
              '%$aiPct',
              style: TextStyle(
                color: recommended ? AppColors.primary : AppColors.textSecondary,
                fontSize: compact ? 9 : 10,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ],
      ),
    );
    if (!recommended) return box;
    // Önerilen seçenek: sağ üstte ✓ rozet
    return Stack(
      clipBehavior: Clip.none,
      children: [
        box,
        Positioned(
          top: -5,
          right: -5,
          child: Container(
            padding: const EdgeInsets.all(2),
            decoration: const BoxDecoration(
              color: AppColors.primary,
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.check, size: 10, color: Colors.black),
          ),
        ),
      ],
    );
  }
}
