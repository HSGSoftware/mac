import 'package:flutter/material.dart';

import '../core/theme.dart';

/// 3 paketli premium bottom sheet'i (Bronz / Gümüş / Altın).
/// [highlightTier] verilirse o paket önceden seçili gelir (1-3).
void showPaywall(BuildContext context, {int highlightTier = 2}) {
  showModalBottomSheet(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (_) => _PaywallSheet(initialTier: highlightTier),
  );
}

class _Package {
  final int tier;
  final String name;
  final String price;
  final Color color;
  final List<String> perks;
  const _Package(this.tier, this.name, this.price, this.color, this.perks);
}

const _packages = [
  _Package(1, 'Bronz', '₺99', Color(0xFFCD9B6A), [
    'Ana Marketler + Gol Marketleri analizi',
    'Günde 15 AI analizi',
    'Model olasılıkları ve gerekçeler',
  ]),
  _Package(2, 'Gümüş', '₺179', Color(0xFFB8C4CE), [
    'Bronz paketteki her şey',
    'Handikap & Kombine market analizleri',
    'Bültende DEĞER sinyalleri ve model favorisi',
    'Günde 40 AI analizi',
  ]),
  _Package(3, 'Altın', '₺279', AppColors.gold, [
    'Gümüş paketteki her şey',
    'Özel marketler dahil TÜM market analizleri',
    'Günün Kuponu (en yüksek değerli seçimler)',
    'Sınırsız AI analizi',
  ]),
];

class _PaywallSheet extends StatefulWidget {
  final int initialTier;
  const _PaywallSheet({required this.initialTier});
  @override
  State<_PaywallSheet> createState() => _PaywallSheetState();
}

class _PaywallSheetState extends State<_PaywallSheet> {
  late int _selected = widget.initialTier.clamp(1, 3).toInt();

  _Package get _pkg => _packages[_selected - 1];

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF161C22),
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
        border: Border(top: BorderSide(color: AppColors.gold, width: 1)),
      ),
      padding: EdgeInsets.fromLTRB(
          20, 12, 20, 24 + MediaQuery.of(context).padding.bottom),
      child: SingleChildScrollView(
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
                const Icon(Icons.workspace_premium,
                    color: AppColors.gold, size: 24),
                const SizedBox(width: 10),
                Text.rich(
                  TextSpan(children: [
                    TextSpan(
                        text: 'Maç Analiz ',
                        style: AppText.sans(size: 19, weight: FontWeight.w800)),
                    TextSpan(
                        text: 'Premium',
                        style: AppText.sans(
                            size: 19,
                            weight: FontWeight.w800,
                            color: AppColors.gold)),
                  ]),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text('Paketin yükseldikçe daha fazla market grubu açılır.',
                style: AppText.sans(
                    size: 12,
                    weight: FontWeight.w500,
                    color: AppColors.textSecondary)),
            const SizedBox(height: 14),
            Row(
              children: _packages
                  .map((p) => Expanded(child: _packageCard(p)))
                  .toList(),
            ),
            const SizedBox(height: 14),
            // Seçili paketin ayrıcalıkları
            Container(
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _pkg.color.withValues(alpha: 0.55)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: _pkg.perks
                    .map((t) => Padding(
                          padding: const EdgeInsets.symmetric(vertical: 4),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Icon(Icons.check, size: 16, color: _pkg.color),
                              const SizedBox(width: 9),
                              Expanded(
                                  child: Text(t,
                                      style: AppText.sans(
                                          size: 12.5,
                                          weight: FontWeight.w600))),
                            ],
                          ),
                        ))
                    .toList(),
              ),
            ),
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: _pkg.color,
                  foregroundColor: const Color(0xFF1E1608),
                  padding: const EdgeInsets.symmetric(vertical: 15),
                ),
                onPressed: () {
                  Navigator.pop(context);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(
                          '${_pkg.name} paketi için ödeme entegrasyonu yakında eklenecek.'),
                    ),
                  );
                },
                child: Text('${_pkg.name} paketini başlat · ${_pkg.price}/ay'),
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
      ),
    );
  }

  Widget _packageCard(_Package p) {
    final selected = _selected == p.tier;
    return GestureDetector(
      onTap: () => setState(() => _selected = p.tier),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 3),
        padding: const EdgeInsets.symmetric(vertical: 13, horizontal: 6),
        decoration: BoxDecoration(
          color: selected
              ? p.color.withValues(alpha: 0.14)
              : AppColors.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: selected ? p.color : AppColors.surface2,
            width: selected ? 1.6 : 1,
          ),
        ),
        child: Column(
          children: [
            if (p.tier == 3)
              Container(
                margin: const EdgeInsets.only(bottom: 6),
                padding:
                    const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                decoration: BoxDecoration(
                  color: AppColors.gold,
                  borderRadius: BorderRadius.circular(5),
                ),
                child: Text('EN İYİ',
                    style: AppText.sans(
                        size: 8,
                        weight: FontWeight.w800,
                        color: const Color(0xFF2A2008),
                        letterSpacing: 0.6)),
              ),
            Text(p.name,
                style: AppText.sans(
                    size: 12.5,
                    weight: FontWeight.w800,
                    color: selected ? p.color : AppColors.textPrimary)),
            const SizedBox(height: 3),
            Text.rich(
              TextSpan(children: [
                TextSpan(
                    text: p.price,
                    style: AppText.sans(size: 16, weight: FontWeight.w800)),
                TextSpan(
                    text: '/ay',
                    style: AppText.sans(
                        size: 10,
                        weight: FontWeight.w600,
                        color: AppColors.textSecondary)),
              ]),
            ),
          ],
        ),
      ),
    );
  }
}
