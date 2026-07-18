import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../models/match.dart';
import 'odds_box.dart';

/// Bülten/favori listelerinde tek maç kartı (profesyonel tasarım, belirgin oranlar).
class MatchCard extends StatelessWidget {
  final MatchItem match;
  final VoidCallback onTap;
  const MatchCard({super.key, required this.match, required this.onTap});

  bool get _isLive => match.status == 'live';

  String get _time {
    final s = match.startTime;
    if (s == null) return '--:--';
    final dt = DateTime.tryParse(s.replaceFirst(' ', 'T'));
    if (dt == null) return '--:--';
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Material(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: onTap,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppColors.surface2),
            ),
            padding: const EdgeInsets.all(14),
            child: Column(
              children: [
                _topRow(),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(child: _teams()),
                    const SizedBox(width: 8),
                    _score(),
                  ],
                ),
                const SizedBox(height: 12),
                _oddsRow(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _topRow() {
    return Row(
      children: [
        Expanded(
          child: Row(
            children: [
              const Icon(Icons.emoji_events, size: 13, color: AppColors.accent),
              const SizedBox(width: 5),
              Flexible(
                child: Text(
                  match.league?.name ?? 'Lig',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: AppColors.textSecondary,
                      fontSize: 11,
                      fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
        if (match.iddaaCode != null) ...[
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
            decoration: BoxDecoration(
              color: AppColors.surface2,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(match.iddaaCode!,
                style: const TextStyle(
                    color: AppColors.textSecondary, fontSize: 10)),
          ),
          const SizedBox(width: 6),
        ],
        if (_isLive)
          _badge('CANLI', AppColors.danger, dot: true)
        else if (match.hasAnalysis)
          _badge('AI', AppColors.primary, icon: Icons.auto_awesome)
        else
          Text(_time,
              style: const TextStyle(
                  color: AppColors.textPrimary,
                  fontSize: 12,
                  fontWeight: FontWeight.bold)),
      ],
    );
  }

  Widget _teams() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _teamRow(match.home.name ?? '-'),
        const SizedBox(height: 8),
        _teamRow(match.away.name ?? '-'),
      ],
    );
  }

  Widget _teamRow(String name) {
    return Row(
      children: [
        Container(
          width: 22,
          height: 22,
          decoration: BoxDecoration(
            color: AppColors.surface2,
            borderRadius: BorderRadius.circular(6),
          ),
          alignment: Alignment.center,
          child: Text(
            name.isNotEmpty ? name.substring(0, 1).toUpperCase() : '?',
            style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.bold,
                color: AppColors.textSecondary),
          ),
        ),
        const SizedBox(width: 9),
        Expanded(
          child: Text(name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  fontWeight: FontWeight.w600, fontSize: 14.5)),
        ),
      ],
    );
  }

  Widget _score() {
    if (match.score != null) {
      return Column(
        children: [
          Text('${match.score!.home}',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17)),
          const SizedBox(height: 8),
          Text('${match.score!.away}',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17)),
        ],
      );
    }
    return const SizedBox.shrink();
  }

  Widget _oddsRow() {
    return Row(
      children: [
        Expanded(child: OddsBox(label: 'MS 1', value: match.odds['MS1'])),
        const SizedBox(width: 7),
        Expanded(child: OddsBox(label: 'MS X', value: match.odds['MSX'])),
        const SizedBox(width: 7),
        Expanded(child: OddsBox(label: 'MS 2', value: match.odds['MS2'])),
        const SizedBox(width: 7),
        Expanded(
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 9),
            decoration: BoxDecoration(
              color: AppColors.surface2,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.bar_chart, size: 14, color: AppColors.accent),
                SizedBox(height: 2),
                Text('Detay',
                    style: TextStyle(
                        color: AppColors.accent,
                        fontSize: 10,
                        fontWeight: FontWeight.w700)),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _badge(String text, Color color, {bool dot = false, IconData? icon}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (dot) ...[
            Container(
                width: 6,
                height: 6,
                decoration:
                    BoxDecoration(color: color, shape: BoxShape.circle)),
            const SizedBox(width: 4),
          ],
          if (icon != null) ...[
            Icon(icon, size: 11, color: color),
            const SizedBox(width: 3),
          ],
          Text(text,
              style: TextStyle(
                  color: color, fontSize: 10, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}
