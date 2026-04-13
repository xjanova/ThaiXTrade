/// TPIX TRADE — Glass Card Widget
/// Glass morphism card 3 แบบ: default, elevated, brand
/// ใช้ตรงกับ web theme (.glass-dark, .glass-card)
///
/// Developed by Xman Studio

import 'dart:ui';
import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';

enum GlassVariant { standard, elevated, brand }

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
    this.borderRadius = 16,
    this.padding,
    this.margin,
    this.onTap,
    this.width,
    this.height,
    this.blurAmount = 12,
  });

  @override
  Widget build(BuildContext context) {
    final decoration = _buildDecoration();

    Widget card = ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: blurAmount, sigmaY: blurAmount),
        child: Container(
          width: width,
          height: height,
          padding: padding ?? const EdgeInsets.all(16),
          decoration: decoration,
          child: child,
        ),
      ),
    );

    if (margin != null) {
      card = Padding(padding: margin!, child: card);
    }

    if (onTap != null) {
      return GestureDetector(
        onTap: onTap,
        child: card,
      );
    }

    return card;
  }

  BoxDecoration _buildDecoration() {
    switch (variant) {
      case GlassVariant.elevated:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0x1A0F1629), // bgSecondary 10%
              Color(0x33151C32), // bgTertiary 20%
            ],
          ),
          border: Border.all(
            color: const Color(0x1AFFFFFF), // 10% white border
            width: 1,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x40000000),
              blurRadius: 24,
              offset: Offset(0, 8),
              spreadRadius: 2,
            ),
            BoxShadow(
              color: Color(0x0D06B6D4), // cyan glow 5%
              blurRadius: 32,
              offset: Offset(0, -2),
            ),
          ],
        );

      case GlassVariant.brand:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          gradient: const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0x1406B6D4), // cyan 8%
              Color(0x148B5CF6), // purple 8%
            ],
          ),
          border: Border.all(
            color: const Color(0x2606B6D4), // cyan 15%
            width: 1,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x33000000),
              blurRadius: 16,
              offset: Offset(0, 6),
            ),
            BoxShadow(
              color: Color(0x1A06B6D4), // cyan glow 10%
              blurRadius: 24,
              spreadRadius: -4,
            ),
          ],
        );

      case GlassVariant.standard:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          color: AppColors.bgCard,
          border: Border.all(
            color: AppColors.bgCardBorder,
            width: 1,
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x33000000),
              blurRadius: 12,
              offset: Offset(0, 4),
            ),
          ],
        );
    }
  }
}

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
    this.borderRadius = 12,
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
