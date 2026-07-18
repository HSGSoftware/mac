import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../models/match.dart';
import '../providers/providers.dart';
import '../widgets/match_card.dart';

/// Bülten/Canlı görünüm seçimi
final bulletinModeProvider = StateProvider<int>((ref) => 0); // 0=Bülten, 1=Canlı

/// Bülten sekmesi: Bülten/Canlı segmenti + tarih şeridi + gruplu maç listesi.
class FixturesScreen extends ConsumerWidget {
  const FixturesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(bulletinModeProvider);
    return Column(
      children: [
        _modeSwitcher(ref, mode),
        if (mode == 0) ...[
          _dateStrip(ref, ref.watch(selectedDateProvider)),
          Expanded(child: _bulletinBody(context, ref)),
        ] else
          Expanded(child: _liveBody(context, ref)),
      ],
    );
  }

  Widget _modeSwitcher(WidgetRef ref, int mode) {
    Widget seg(String label, int value, {bool live = false}) {
      final selected = mode == value;
      return Expanded(
        child: GestureDetector(
          onTap: () {
            ref.read(bulletinModeProvider.notifier).state = value;
            if (value == 1) ref.invalidate(liveMatchesProvider);
          },
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            padding: const EdgeInsets.symmetric(vertical: 10),
            decoration: BoxDecoration(
              color: selected
                  ? (live ? AppColors.danger : AppColors.primary)
                  : Colors.transparent,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (live) ...[
                  Container(
                    width: 7,
                    height: 7,
                    decoration: BoxDecoration(
                      color: selected ? AppColors.bg : AppColors.danger,
                      shape: BoxShape.circle,
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
                Text(label,
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                      color: selected ? AppColors.bg : AppColors.textSecondary,
                    )),
              ],
            ),
          ),
        ),
      );
    }

    return Container(
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 4),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.surface2),
      ),
      child: Row(
        children: [
          seg('Bülten', 0),
          seg('CANLI', 1, live: true),
        ],
      ),
    );
  }

  Widget _bulletinBody(BuildContext context, WidgetRef ref) {
    final fixtures = ref.watch(fixturesProvider);
    return fixtures.when(
      loading: () => const Center(
          child: CircularProgressIndicator(color: AppColors.primary)),
      error: (e, _) => _errorView(ref, e.toString()),
      data: (groups) {
        if (groups.isEmpty) {
          return const _EmptyView();
        }
        return RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () async => ref.invalidate(fixturesProvider),
          child: ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: groups.length,
            itemBuilder: (c, i) => _leagueSection(context, groups[i]),
          ),
        );
      },
    );
  }

  Widget _liveBody(BuildContext context, WidgetRef ref) {
    final live = ref.watch(liveMatchesProvider);
    return live.when(
      loading: () => const Center(
          child: CircularProgressIndicator(color: AppColors.danger)),
      error: (e, _) => _errorView(ref, e.toString()),
      data: (matches) {
        if (matches.isEmpty) {
          return Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.sports_soccer,
                    size: 44, color: AppColors.textSecondary),
                const SizedBox(height: 8),
                const Text('Şu an canlı maç yok.',
                    style: TextStyle(color: AppColors.textSecondary)),
                TextButton(
                  onPressed: () => ref.invalidate(liveMatchesProvider),
                  child: const Text('Yenile'),
                ),
              ],
            ),
          );
        }
        return RefreshIndicator(
          color: AppColors.danger,
          onRefresh: () async => ref.invalidate(liveMatchesProvider),
          child: ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: matches.length,
            itemBuilder: (c, i) => MatchCard(
              match: matches[i],
              onTap: () => context.push('/match/${matches[i].id}'),
            ),
          ),
        );
      },
    );
  }

  Widget _dateStrip(WidgetRef ref, DateTime selected) {
    final days = List.generate(7, (i) => DateTime.now().add(Duration(days: i)));
    return SizedBox(
      height: 74,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        itemCount: days.length,
        itemBuilder: (c, i) {
          final d = days[i];
          final isSel = d.year == selected.year &&
              d.month == selected.month &&
              d.day == selected.day;
          return GestureDetector(
            onTap: () =>
                ref.read(selectedDateProvider.notifier).state = d,
            child: Container(
              width: 60,
              margin: const EdgeInsets.symmetric(horizontal: 4),
              decoration: BoxDecoration(
                color: isSel ? AppColors.primary : AppColors.surface,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(DateFormat.E('tr_TR').format(d),
                      style: TextStyle(
                          fontSize: 12,
                          color: isSel ? AppColors.bg : AppColors.textSecondary)),
                  Text(DateFormat.d().format(d),
                      style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: isSel ? AppColors.bg : AppColors.textPrimary)),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _leagueSection(BuildContext context, LeagueGroup g) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 12, 4, 4),
          child: Row(
            children: [
              const Icon(Icons.emoji_events, size: 16, color: AppColors.accent),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '${g.league.country != null ? '${g.league.country} · ' : ''}${g.league.name ?? 'Lig'}',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, color: AppColors.accent),
                ),
              ),
            ],
          ),
        ),
        ...g.matches.map((m) => MatchCard(
              match: m,
              onTap: () => context.push('/match/${m.id}'),
            )),
      ],
    );
  }

  Widget _errorView(WidgetRef ref, String msg) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.wifi_off, color: AppColors.danger, size: 40),
            const SizedBox(height: 8),
            Text(msg, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            TextButton(
                onPressed: () => ref.invalidate(fixturesProvider),
                child: const Text('Tekrar dene')),
          ],
        ),
      );
}

class _EmptyView extends StatelessWidget {
  const _EmptyView();
  @override
  Widget build(BuildContext context) => const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.calendar_today, color: AppColors.textSecondary, size: 40),
            SizedBox(height: 8),
            Text('Bu tarihte maç bulunamadı.',
                style: TextStyle(color: AppColors.textSecondary)),
          ],
        ),
      );
}
