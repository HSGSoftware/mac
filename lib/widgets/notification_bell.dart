import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/theme.dart';
import '../providers/providers.dart';
import '../screens/notifications_sheet.dart';

/// Okunmamış bildirim rozetli zil ikonu. Başlıklarda kullanılır; dokununca
/// bildirim merkezini açar.
class NotificationBell extends ConsumerWidget {
  final Color? color;
  const NotificationBell({super.key, this.color});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(notificationsProvider).unread;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        IconButton(
          onPressed: () => showNotifications(context),
          visualDensity: VisualDensity.compact,
          icon: Icon(Icons.notifications_none,
              size: 22, color: color ?? AppColors.textPrimary),
          tooltip: 'Bildirimler',
        ),
        if (unread > 0)
          Positioned(
            top: 6,
            right: 6,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
              constraints: const BoxConstraints(minWidth: 15),
              decoration: BoxDecoration(
                color: AppColors.danger,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF0A0F15), width: 1.5),
              ),
              alignment: Alignment.center,
              child: Text(
                unread > 9 ? '9+' : '$unread',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 8.5,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
      ],
    );
  }
}
