import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../models/notification.dart';
import '../providers/providers.dart';

/// Bildirim merkezi (alttan açılan panel). Analiz hazır olduğunda düşen
/// bildirimler burada listelenir; dokununca ilgili maça gider.
void showNotifications(BuildContext context) {
  showModalBottomSheet(
    context: context,
    backgroundColor: AppColors.surface,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
    ),
    builder: (_) => const _NotificationsSheet(),
  );
}

class _NotificationsSheet extends ConsumerStatefulWidget {
  const _NotificationsSheet();
  @override
  ConsumerState<_NotificationsSheet> createState() => _NotificationsSheetState();
}

class _NotificationsSheetState extends ConsumerState<_NotificationsSheet> {
  @override
  void initState() {
    super.initState();
    // Panel açılınca en güncel listeyi çek
    Future.microtask(() => ref.read(notificationsProvider.notifier).refresh());
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(notificationsProvider);
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.6,
      minChildSize: 0.4,
      maxChildSize: 0.92,
      builder: (context, controller) {
        return Column(
          children: [
            const SizedBox(height: 10),
            Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.surface2,
                borderRadius: BorderRadius.circular(3),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 12, 10, 6),
              child: Row(
                children: [
                  const Icon(Icons.notifications, size: 18, color: AppColors.primary),
                  const SizedBox(width: 8),
                  Text('Bildirimler',
                      style: AppText.sans(size: 15, weight: FontWeight.w800)),
                  const Spacer(),
                  if (state.items.any((n) => !n.isRead))
                    TextButton(
                      onPressed: () =>
                          ref.read(notificationsProvider.notifier).markAllRead(),
                      child: Text('Tümünü okundu işaretle',
                          style: AppText.sans(
                              size: 11,
                              weight: FontWeight.w700,
                              color: AppColors.primary)),
                    ),
                ],
              ),
            ),
            Expanded(
              child: state.items.isEmpty
                  ? _empty()
                  : ListView.separated(
                      controller: controller,
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 20),
                      itemCount: state.items.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (_, i) => _tile(context, state.items[i]),
                    ),
            ),
          ],
        );
      },
    );
  }

  Widget _tile(BuildContext context, AppNotification n) {
    final (icon, color) = _visual(n.type);
    return GestureDetector(
      onTap: () {
        // Router'ı pop'tan ÖNCE al; panel kapandıktan sonra bu context ölür.
        final router = GoRouter.of(context);
        ref.read(notificationsProvider.notifier).markRead(n.id);
        Navigator.of(context).pop();
        if (n.matchId != null) router.push('/match/${n.matchId}');
      },
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: n.isRead ? AppColors.surface2 : AppColors.accentFaint,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
              color: n.isRead ? AppColors.surface2 : AppColors.accentDim),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: color),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(n.title,
                      style: AppText.sans(size: 12.5, weight: FontWeight.w700)),
                  if (n.body != null && n.body!.isNotEmpty) ...[
                    const SizedBox(height: 3),
                    Text(n.body!,
                        style: AppText.sans(
                            size: 11,
                            weight: FontWeight.w500,
                            color: AppColors.textSecondary)),
                  ],
                  const SizedBox(height: 4),
                  Text(_time(n.createdAt),
                      style: AppText.mono(size: 9.5, color: AppColors.textMuted)),
                ],
              ),
            ),
            if (!n.isRead)
              Container(
                margin: const EdgeInsets.only(left: 6, top: 3),
                width: 8,
                height: 8,
                decoration: const BoxDecoration(
                    color: AppColors.primary, shape: BoxShape.circle),
              ),
          ],
        ),
      ),
    );
  }

  (IconData, Color) _visual(String type) {
    switch (type) {
      case 'analysis_ready':
        return (Icons.auto_awesome, AppColors.primary);
      case 'analysis_failed':
        return (Icons.error_outline, AppColors.danger);
      default:
        return (Icons.info_outline, AppColors.textSecondary);
    }
  }

  Widget _empty() => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.notifications_none, size: 42, color: AppColors.textMuted),
            const SizedBox(height: 10),
            Text('Henüz bildirim yok.',
                style: AppText.sans(size: 12.5, color: AppColors.textSecondary)),
            const SizedBox(height: 4),
            Text('Analizleriniz hazır olduğunda burada göreceksiniz.',
                textAlign: TextAlign.center,
                style: AppText.sans(
                    size: 10.5, weight: FontWeight.w500, color: AppColors.textMuted)),
          ],
        ),
      );

  String _time(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso.replaceFirst(' ', 'T'));
    if (dt == null) return '';
    final now = DateTime.now();
    final diff = now.difference(dt);
    if (diff.inMinutes < 1) return 'az önce';
    if (diff.inMinutes < 60) return '${diff.inMinutes} dk önce';
    if (diff.inHours < 24) return '${diff.inHours} saat önce';
    return DateFormat('d MMM HH:mm', 'tr_TR').format(dt);
  }
}
