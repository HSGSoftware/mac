import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../models/match.dart';
import 'badges.dart';

/// Bülten/canlı listelerinde tek maç kartı (Maç Analiz tasarımı).
class MatchCard extends StatelessWidget {
  final MatchItem match;
  final VoidCallback onTap;
  final bool premium;
  const MatchCard({
    super.key,
    required this.match,
    required this.onTap,
    this.premium = false,
  });

  bool get _isLive => match.status == 'live';

  String get _dayTime {
    final s = match.startTime;
    if (s == null) return '--:--';
    final dt = DateTime.tryParse(s.replaceFirst(' ', 'T'));
    if (dt == null) return '--:--';
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final d = DateTime(dt.year, dt.month, dt.day);
    final diff = d.difference(today).inDays;
    final time =
        '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    if (diff == 0) return 'Bugün $time';
    if (diff == 1) return 'Yarın $time';
    return '${DateFormat('d MMM', 'tr_TR').format(dt)} $time';
  }

  @override
  Widget build(BuildContext context) {
    final sig = match.signal;
    final showValue = premium && sig != null && sig.hasValue;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Ink(
            decoration: BoxDecoration(
              gradient: AppColors.cardGradient,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.surface2),
              boxShadow: const [
                BoxShadow(color: Color(0x40000000), blurRadius: 8, offset: Offset(0, 2)),
              ],
            ),
            padding: const EdgeInsets.fromLTRB(14, 13, 14, 13),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        (match.league?.name ?? 'Lig').toUpperCase(),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppText.sans(
                          size: 9.5,
                          weight: FontWeight.w600,
                          color: AppColors.textSecondary,
                          letterSpacing: 0.5,
                        ),
                      ),
                    ),
                    if (showValue) ...[
                      const SizedBox(width: 6),
                      const ValueBadge(),
                    ],
                    const SizedBox(width: 8),
                    if (_isLive)
                      LiveMinute(minute: match.minute)
                    else
                      Text(_dayTime, style: AppText.mono(size: 10.5, color: AppColors.textPrimary)),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(match.home.name ?? '-',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppText.sans(size: 14, weight: FontWeight.w700)),
                          const SizedBox(height: 5),
                          Text(match.away.name ?? '-',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppText.sans(size: 14, weight: FontWeight.w700)),
                        ],
                      ),
                    ),
                    if (_isLive && match.score != null) ...[
                      const SizedBox(width: 10),
                      Column(
                        children: [
                          Text('${match.score!.home}', style: AppText.mono(size: 16)),
                          const SizedBox(height: 5),
                          Text('${match.score!.away}', style: AppText.mono(size: 16)),
                        ],
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 11),
                Row(
                  children: [
                    _oddCell('1', match.odds['MS1'], sig, 'MS1'),
                    const SizedBox(width: 8),
                    _oddCell('X', match.odds['MSX'], sig, 'MSX'),
                    const SizedBox(width: 8),
                    _oddCell('2', match.odds['MS2'], sig, 'MS2'),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _oddCell(String label, double? value, MatchSignal? sig, String code) {
    final isFav = premium && sig != null && sig.pick == code;
    final isValue = premium && sig != null && sig.hasValue && sig.pick == code;
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: AppColors.oddCell,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: isFav ? AppColors.primary : AppColors.oddBorder,
            width: 1.5,
          ),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(label,
                style: AppText.sans(
                    size: 9,
                    weight: FontWeight.w600,
                    color: AppColors.textSecondary,
                    letterSpacing: 0.8)),
            const SizedBox(height: 2),
            Text(
              value != null ? value.toStringAsFixed(2) : '-',
              style: AppText.mono(
                size: 14.5,
                color: isValue ? AppColors.primary : AppColors.textPrimary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
