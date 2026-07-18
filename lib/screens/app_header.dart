import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../widgets/badges.dart';

/// Üst başlık: MA logosu + "Maç Analiz" + (premium ise) PREMIUM rozeti + durum.
class AppHeader extends StatelessWidget {
  final bool premium;
  const AppHeader({super.key, this.premium = false});

  @override
  Widget build(BuildContext context) {
    final now = TimeOfDay.now();
    final clock =
        '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}';
    return Container(
      decoration: const BoxDecoration(gradient: AppColors.headerGradient),
      padding: EdgeInsets.fromLTRB(
          18, MediaQuery.of(context).padding.top + 10, 18, 12),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              gradient: AppColors.primaryGradient,
              borderRadius: BorderRadius.circular(10),
            ),
            alignment: Alignment.center,
            child: Text('MA',
                style: AppText.sans(
                    size: 13,
                    weight: FontWeight.w800,
                    color: const Color(0xFF0A1410),
                    letterSpacing: -0.3)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text('Maç Analiz',
                        style: AppText.sans(size: 16.5, weight: FontWeight.w800)),
                    if (premium) ...[
                      const SizedBox(width: 7),
                      const PremiumBadge(),
                    ],
                  ],
                ),
                const SizedBox(height: 1),
                Text('Analiz platformu · bahis tavsiyesi değildir',
                    style: AppText.sans(
                        size: 9.5,
                        weight: FontWeight.w500,
                        color: AppColors.textMuted)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 6,
                    height: 6,
                    decoration: const BoxDecoration(
                        color: AppColors.primary, shape: BoxShape.circle),
                  ),
                  const SizedBox(width: 4),
                  Text('Model güncel',
                      style: AppText.sans(
                          size: 9.5,
                          weight: FontWeight.w500,
                          color: AppColors.textSecondary)),
                ],
              ),
              const SizedBox(height: 2),
              Text(clock, style: AppText.mono(size: 10, color: AppColors.textSecondary)),
            ],
          ),
        ],
      ),
    );
  }
}

/// Basit ekran başlığı (büyük başlık + tarih), Kupon/Analizlerim için.
class ScreenTitle extends StatelessWidget {
  final String title;
  final String? subtitle;
  final bool showDate;
  const ScreenTitle({super.key, required this.title, this.subtitle, this.showDate = false});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
          18, MediaQuery.of(context).padding.top + 14, 18, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Text(title,
                    style: AppText.sans(size: 19, weight: FontWeight.w800)),
              ),
              if (showDate)
                Text(DateFormat('d MMMM yyyy', 'tr_TR').format(DateTime.now()),
                    style: AppText.mono(size: 10, color: AppColors.textSecondary)),
            ],
          ),
          if (subtitle != null) ...[
            const SizedBox(height: 4),
            Text(subtitle!,
                style: AppText.sans(
                    size: 11,
                    weight: FontWeight.w500,
                    color: AppColors.textSecondary)),
          ],
        ],
      ),
    );
  }
}
