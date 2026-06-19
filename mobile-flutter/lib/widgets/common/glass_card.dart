/// TPIX TRADE — Glass Card Widget
/// "Luxury Dark / Gilded Metal" cards. Variants:
///   • standard  — dark glass list/section card (neutral hairline border)
///   • elevated  — metallic fill + stronger shadow
///   • brand     — gold-tinted accent card (thin gold edge)
///   • gold      — gold-tinted accent with bright gilded edge
///   • hero      — metallic fill + 1.6px gilded edge + diagonal metal sheen
///
/// Gold edges/tints follow the active metal tone (AccentProvider).
///
/// Developed by Xman Studio

import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';
import '../../providers/accent_provider.dart';

enum GlassVariant { standard, elevated, brand, gold, hero }

class GlassCard extends StatelessWidget {
  final Widget child;
  final GlassVariant variant;
  final double borderRadius;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final VoidCallback? onTap;
  final double? width;
  final double? height;
  final double blurAmount;

  const GlassCard({
    super.key,
    required this.child,
    this.variant = GlassVariant.standard,
    this.borderRadius = 18,
    this.padding,
    this.margin,
    this.onTap,
    this.width,
    this.height,
    this.blurAmount = 12,
  });

  bool get _hasSheen =>
      variant == GlassVariant.hero || variant == GlassVariant.gold;

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final decoration = _buildDecoration(accent);

    Widget content = child;
    if (_hasSheen) {
      // Diagonal metal-sheen highlight skewed across the upper-left.
      content = Stack(
        children: [
          Positioned.fill(
            child: IgnorePointer(
              child: Transform(
                alignment: Alignment.center,
                transform: Matrix4.skewX(-0.32), // ~-18deg
                child: FractionallySizedBox(
                  alignment: Alignment.topLeft,
                  widthFactor: 0.58,
                  heightFactor: 1,
                  child: DecoratedBox(
                    decoration: const BoxDecoration(gradient: AppGradients.metalSheen),
                  ),
                ),
              ),
            ),
          ),
          child,
        ],
      );
    }

    Widget card = ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: blurAmount, sigmaY: blurAmount),
        child: Container(
          width: width,
          height: height,
          padding: padding ?? const EdgeInsets.all(16),
          decoration: decoration,
          child: content,
        ),
      ),
    );

    if (margin != null) {
      card = Padding(padding: margin!, child: card);
    }

    if (onTap != null) {
      return GestureDetector(onTap: onTap, child: card);
    }
    return card;
  }

  BoxDecoration _buildDecoration(AccentProvider accent) {
    switch (variant) {
      case GlassVariant.elevated:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: AppGradients.glassCard,
          border: Border.all(color: const Color(0x14FFFFFF), width: 1),
          boxShadow: const [
            BoxShadow(
              color: Color(0xD9000000), // 0 22px 38px -22px rgba(0,0,0,0.85)
              blurRadius: 38,
              offset: Offset(0, 22),
              spreadRadius: -22,
            ),
          ],
        );

      case GlassVariant.brand:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: AppGradients.cardSubtle,
          border: Border.all(color: accent.goldBorder, width: 1),
          boxShadow: [
            const BoxShadow(
              color: Color(0x59000000),
              blurRadius: 24,
              offset: Offset(0, 10),
            ),
            BoxShadow(
              color: accent.goldGlow.withValues(alpha: 0.12),
              blurRadius: 28,
              spreadRadius: -6,
            ),
          ],
        );

      case GlassVariant.gold:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: AppGradients.glassCard,
          border: Border.all(color: accent.goldBorder, width: kGoldEdgeWidth),
          boxShadow: [
            const BoxShadow(
              color: Color(0xD9000000),
              blurRadius: 38,
              offset: Offset(0, 22),
              spreadRadius: -22,
            ),
            BoxShadow(
              color: accent.goldGlow.withValues(alpha: 0.16),
              blurRadius: 26,
              spreadRadius: -8,
            ),
          ],
        );

      case GlassVariant.hero:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: AppGradients.glassCard,
          border: Border.all(color: accent.goldBorder, width: kGoldEdgeWidth),
          boxShadow: const [
            BoxShadow(
              color: Color(0xD9000000),
              blurRadius: 38,
              offset: Offset(0, 22),
              spreadRadius: -22,
            ),
          ],
        );

      case GlassVariant.standard:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          color: AppColors.bgCard,
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
          boxShadow: const [
            BoxShadow(
              color: Color(0x40000000),
              blurRadius: 12,
              offset: Offset(0, 4),
            ),
          ],
        );
    }
  }
}

/// Signature gilded-edge width (1.6px) — matches AppTheme.goldBorderWidth.
const double kGoldEdgeWidth = 1.6;


/// Simplified glass container (no blur) สำหรับ performance-critical lists
class GlassContainer extends StatelessWidget {
  final Widget child;
  final double borderRadius;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final Color? backgroundColor;
  final Color? borderColor;

  const GlassContainer({
    super.key,
    required this.child,
    this.borderRadius = 14,
    this.padding,
    this.margin,
    this.backgroundColor,
    this.borderColor,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding ?? const EdgeInsets.all(12),
      margin: margin,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(borderRadius),
        color: backgroundColor ?? AppColors.bgCard,
        border: Border.all(
          color: borderColor ?? AppColors.bgCardBorder,
          width: 1,
        ),
      ),
      child: child,
    );
  }
}
