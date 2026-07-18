import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Premium tanıtım/satın alma bottom sheet'i (tasarımdaki paywall).
void showPaywall(BuildContext context) {
  showModalBottomSheet(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (_) => const _PaywallSheet(),
  );
}

const _perks = [
  'Model kazanma olasılıkları',
  'DEĞER sinyalleri ve bildirimleri',
  'Analiz gerekçeleri + güven puanı',
  'Canlı maç istatistikleri',
];

class _PaywallSheet extends StatelessWidget {
  const _PaywallSheet();

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF161C22),
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
        border: Border(top: BorderSide(color: AppColors.gold, width: 1)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 30),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(
                color: AppColors.surface2,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          Row(
            children: [
              const Icon(Icons.workspace_premium, color: AppColors.gold, size: 24),
              const SizedBox(width: 10),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: 'Maç Analiz ', style: AppText.sans(size: 19, weight: FontWeight.w800)),
                    TextSpan(
                        text: 'Premium',
                        style: AppText.sans(
                            size: 19, weight: FontWeight.w800, color: AppColors.gold)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text('Modelin gördüğü her şeyi görün.',
              style: AppText.sans(
                  size: 12, weight: FontWeight.w500, color: AppColors.textSecondary)),
          const SizedBox(height: 16),
          ..._perks.map((p) => Padding(
                padding: const EdgeInsets.only(bottom: 9),
                child: Row(
                  children: [
                    const Icon(Icons.check, color: AppColors.gold, size: 17),
                    const SizedBox(width: 10),
                    Expanded(
                        child: Text(p,
                            style: AppText.sans(size: 12.5, weight: FontWeight.w600))),
                  ],
                ),
              )),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _PlanCard(title: 'Aylık', price: '₺149', unit: '/ay'),
              ),
              const SizedBox(width: 9),
              Expanded(
                child: _PlanCard(
                    title: 'Yıllık',
                    price: '₺89',
                    unit: '/ay',
                    highlight: true,
                    badge: '%40 İNDİRİM'),
              ),
            ],
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.gold,
                foregroundColor: const Color(0xFF2A2008),
                padding: const EdgeInsets.symmetric(vertical: 15),
              ),
              onPressed: () {
                Navigator.pop(context);
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text(
                        'Ödeme entegrasyonu yakında eklenecek (mağaza içi satın alma).'),
                  ),
                );
              },
              child: const Text("Premium'u başlat"),
            ),
          ),
          const SizedBox(height: 4),
          Center(
            child: TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('Şimdi değil',
                  style: AppText.sans(
                      size: 12.5,
                      weight: FontWeight.w600,
                      color: AppColors.textSecondary)),
            ),
          ),
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  final String title;
  final String price;
  final String unit;
  final bool highlight;
  final String? badge;
  const _PlanCard({
    required this.title,
    required this.price,
    required this.unit,
    this.highlight = false,
    this.badge,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
      decoration: BoxDecoration(
        color: highlight ? AppColors.gold.withValues(alpha: 0.12) : AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: highlight ? AppColors.gold : AppColors.surface2,
          width: highlight ? 1.5 : 1,
        ),
      ),
      child: Column(
        children: [
          if (badge != null)
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2.5),
              decoration: BoxDecoration(
                color: AppColors.gold,
                borderRadius: BorderRadius.circular(5),
              ),
              child: Text(badge!,
                  style: AppText.sans(
                      size: 8.5,
                      weight: FontWeight.w800,
                      color: const Color(0xFF2A2008),
                      letterSpacing: 0.5)),
            ),
          Text(title,
              style: AppText.sans(
                  size: 10.5,
                  weight: FontWeight.w700,
                  color: highlight ? AppColors.gold : AppColors.textSecondary)),
          const SizedBox(height: 3),
          Text.rich(
            TextSpan(children: [
              TextSpan(text: price, style: AppText.sans(size: 17, weight: FontWeight.w800)),
              TextSpan(
                  text: unit,
                  style: AppText.sans(
                      size: 10.5,
                      weight: FontWeight.w600,
                      color: AppColors.textSecondary)),
            ]),
          ),
        ],
      ),
    );
  }
}
