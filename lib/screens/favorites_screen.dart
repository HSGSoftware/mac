import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../providers/providers.dart';
import '../widgets/match_card.dart';

class FavoritesScreen extends ConsumerWidget {
  const FavoritesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final favs = ref.watch(favoritesProvider);
    return favs.when(
      loading: () =>
          const Center(child: CircularProgressIndicator(color: AppColors.primary)),
      error: (e, _) => Center(child: Text('Hata: $e')),
      data: (matches) {
        if (matches.isEmpty) {
          return const Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.star_border,
                    size: 44, color: AppColors.textSecondary),
                SizedBox(height: 8),
                Text('Henüz favori maçın yok.',
                    style: TextStyle(color: AppColors.textSecondary)),
              ],
            ),
          );
        }
        return RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () async => ref.invalidate(favoritesProvider),
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
}
