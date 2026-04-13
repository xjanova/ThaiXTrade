/// TPIX TRADE — Price Text Widget
/// แสดงราคาด้วย JetBrains Mono + สีตาม +/-
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme/app_colors.dart';

class PriceText extends StatelessWidget {
  final double price;
  final double? change;
  final double fontSize;
  final FontWeight fontWeight;
  final int decimals;

  const PriceText({
    super.key,
    required this.price,
    this.change,
    this.fontSize = 15,
    this.fontWeight = FontWeight.w600,
    this.decimals = 2,
  });

  @override
  Widget build(BuildContext context) {
    return Text(
      _formatPrice(price),
      style: GoogleFonts.jetBrainsMono(
        fontSize: fontSize,
        fontWeight: fontWeight,
        color: change != null
            ? (change! >= 0 ? AppColors.tradingGreen : AppColors.tradingRed)
            : AppColors.textPrimary,
      ),
    );
  }

  String _formatPrice(double value) {
    if (value >= 1000) {
      return value.toStringAsFixed(decimals);
    } else if (value >= 1) {
      return value.toStringAsFixed(decimals + 2);
    } else {
      return value.toStringAsFixed(decimals + 4);
    }
  }
}

/// Change badge (+2.5%) — สีเขียว/แดง ตาม +/-
class ChangeBadge extends StatelessWidget {
  final double changePercent;
  final double fontSize;

  const ChangeBadge({
    super.key,
    required this.changePercent,
    this.fontSize = 12,
  });

  @override
  Widget build(BuildContext context) {
    final isPositive = changePercent >= 0;
    final color = isPositive ? AppColors.tradingGreen : AppColors.tradingRed;
    final bgColor = isPositive ? AppColors.tradingGreenBg : AppColors.tradingRedBg;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        '${isPositive ? '+' : ''}${changePercent.toStringAsFixed(2)}%',
        style: GoogleFonts.jetBrainsMono(
          fontSize: fontSize,
          fontWeight: FontWeight.w600,
          color: color,
        ),
      ),
    );
  }
}

/// Volume text — abbreviated (1.2M, 345K)
class VolumeText extends StatelessWidget {
  final double volume;
  final double fontSize;

  const VolumeText({
    super.key,
    required this.volume,
    this.fontSize = 11,
  });

  @override
  Widget build(BuildContext context) {
    return Text(
      _formatVolume(volume),
      style: GoogleFonts.jetBrainsMono(
        fontSize: fontSize,
        fontWeight: FontWeight.w500,
        color: AppColors.textTertiary,
      ),
    );
  }

  String _formatVolume(double value) {
    if (value >= 1e9) return '${(value / 1e9).toStringAsFixed(1)}B';
    if (value >= 1e6) return '${(value / 1e6).toStringAsFixed(1)}M';
    if (value >= 1e3) return '${(value / 1e3).toStringAsFixed(1)}K';
    return value.toStringAsFixed(0);
  }
}
