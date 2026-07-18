import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../models/match.dart';

/// Bülten/favori listelerinde tek maç satırı.
class MatchCard extends StatelessWidget {
  final MatchItem match;
  final VoidCallback onTap;
  const MatchCard({super.key, required this.match, required this.onTap});

  String get _time {
    final s = match.startTime;
    if (s == null) return '--:--';
    final dt = DateTime.tryParse(s.replaceFirst(' ', 'T'));
    if (dt == null) return '--:--';
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              SizedBox(
                width: 44,
                child: Column(
                  children: [
                    Text(_time,
                        style: const TextStyle(
                            color: AppColors.textSecondary,
                            fontWeight: FontWeight.bold)),
                    if (match.iddaaCode != null)
                      Text(match.iddaaCode!,
                          style: const TextStyle(
                              color: AppColors.textSecondary, fontSize: 10)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(match.home.name ?? '-',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Text(match.away.name ?? '-',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                  ],
                ),
              ),
              if (match.score != null)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Text('${match.score!.home}-${match.score!.away}',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 16)),
                ),
              _oddsColumn(),
              const SizedBox(width: 4),
              if (match.hasAnalysis)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: AppColors.primary.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.smart_toy,
                      size: 16, color: AppColors.primary),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _oddsColumn() {
    Widget cell(String label, double? v) => Column(
          children: [
            Text(label,
                style: const TextStyle(
                    color: AppColors.textSecondary, fontSize: 9)),
            Text(v != null ? v.toStringAsFixed(2) : '-',
                style: const TextStyle(fontSize: 12)),
          ],
        );
    return Row(
      children: [
        cell('1', match.odds['MS1']),
        const SizedBox(width: 8),
        cell('X', match.odds['MSX']),
        const SizedBox(width: 8),
        cell('2', match.odds['MS2']),
      ],
    );
  }
}
