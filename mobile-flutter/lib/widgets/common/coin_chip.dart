/// TPIX TRADE — Coin Chip
/// The signature gilded coin token: a gold-gradient ring (2px) with a thin
/// gold rim around the colored coin logo on a near-black disc. Re-skins with
/// the active metal tone via AccentProvider.
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../providers/accent_provider.dart';
import 'coin_logo.dart';

class CoinChip extends StatelessWidget {
  final String symbol;

  /// Outer diameter of the chip (ring included). Design uses 36–40px.
  final double size;
  final String? logoUrl;

  const CoinChip({
    super.key,
    required this.symbol,
    this.size = 38,
    this.logoUrl,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final inner = size - 4; // 2px gold ring on each side

    return Container(
      width: size,
      height: size,
      padding: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: accent.goldGradient,
        boxShadow: [
          BoxShadow(
            color: accent.goldBorder,
            blurRadius: 0,
            spreadRadius: 1.5, // box-shadow 0 0 0 1.5px var(--gline)
          ),
        ],
      ),
      child: Container(
        decoration: const BoxDecoration(
          shape: BoxShape.circle,
          color: AppColors.bgGradBottom, // #0E0F14
        ),
        child: ClipOval(
          child: CoinLogo(
            symbol: symbol,
            size: inner,
            borderRadius: inner / 2,
            logoUrl: logoUrl,
          ),
        ),
      ),
    );
  }
}
