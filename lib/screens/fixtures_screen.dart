import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../models/match.dart';
import '../providers/providers.dart';
import '../widgets/match_card.dart';

/// Bülten sekmesi: tarih şeridi + lige göre gruplu maç listesi.
class FixturesScreen extends ConsumerWidget {
  const FixturesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final fixtures = ref.watch(fixturesProvider);
    final selectedDate = ref.watch(selectedDateProvider);

    return Column(
      children: [
        _dateStrip(ref, selectedDate),
        Expanded(
          child: fixtures.when(
            loading: () =>
                const Center(child: CircularProgressIndicator(color: AppColors.primary)),
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
          ),
        ),
      ],
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
