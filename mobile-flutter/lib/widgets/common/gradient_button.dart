/// TPIX TRADE — Gradient Button
/// Gilded CTAs. Variants:
///   • gold / brand — champagne-gold gradient, dark text, gold glow (default CTA)
///   • buy          — green gradient, white text (Buy / Execute)
///   • sell         — red gradient, white text
///   • outline      — gold hairline, gold text
/// Gold variants follow the active metal tone (AccentProvider).
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../core/theme/gradients.dart';
import '../../providers/accent_provider.dart';
import '../obsidian/obsidian_surface.dart';

enum ButtonVariant { brand, gold, buy, sell, outline }

class GradientButton extends StatelessWidget {
  final String text;
  final VoidCallback? onPressed;
  final ButtonVariant variant;
  final bool isLoading;
  final IconData? icon;
  final double height;
  final double borderRadius;
  final bool fullWidth;

  const GradientButton({
    super.key,
    required this.text,
    this.onPressed,
    this.variant = ButtonVariant.gold,
    this.isLoading = false,
    this.icon,
    this.height = 50,
    this.borderRadius = 14,
    this.fullWidth = true,
  });

  bool get _isGold =>
      variant == ButtonVariant.gold || variant == ButtonVariant.brand;

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final isDisabled = onPressed == null || isLoading;

    final gradient = _gradient(accent);
    final textColor = _textColor(accent);
    final glow = _glowColor(accent);

    Widget button = AppColors.palette.obsidian
        ? _buildObsidian(isDisabled, textColor)
        : Container(
      height: height,
      width: fullWidth ? double.infinity : null,
      decoration: BoxDecoration(
        gradient: isDisabled ? null : gradient,
        color: isDisabled ? AppColors.bgTertiary : null,
        borderRadius: BorderRadius.circular(borderRadius),
        border: variant == ButtonVariant.outline
            ? Border.all(color: accent.g2, width: 1.6)
            : null,
        boxShadow: isDisabled
            ? null
            : [
                BoxShadow(
                  color: glow.withValues(alpha: _isGold ? 0.45 : 0.32),
                  blurRadius: _isGold ? 22 : 14,
                  offset: const Offset(0, 6),
                  spreadRadius: _isGold ? -6 : 0,
                ),
              ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: isDisabled ? null : onPressed,
          borderRadius: BorderRadius.circular(borderRadius),
          child: Center(
            child: isLoading
                ? SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: textColor,
                    ),
                  )
                : Row(
                    mainAxisSize:
                        fullWidth ? MainAxisSize.max : MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (icon != null) ...[
                        Icon(icon, color: textColor, size: 18),
                        const SizedBox(width: 8),
                      ],
                      Text(
                        text,
                        style: TextStyle(
                          color: textColor,
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.3,
                        ),
                      ),
                      if (!fullWidth && icon != null) const SizedBox(width: 4),
                    ],
                  ),
          ),
        ),
      ),
    );

    if (!fullWidth) {
      button = Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: button,
      );
    }

    return button;
  }

  /// ธีม Obsidian — ปุ่มนูนสูตรเดียวกับ TPIX Wallet
  ///   gold/brand → ขาวนูนเรืองม่วง · buy/sell → ไล่สีทิศราคา + ไฮไลต์บน
  ///   outline → กระจกเข้มขอบเงิน · ปิดใช้งาน → แผ่นเข้มจาง
  Widget _buildObsidian(bool isDisabled, Color textColor) {
    final r = borderRadius < 19 ? 19.0 : borderRadius;
    final ObsidianSurface surface;
    var fg = textColor;
    if (isDisabled && !isLoading) {
      surface = ObsidianSurface.dark.copyWith(radius: r, shadows: const []);
      fg = AppColors.textDisabled;
    } else {
      switch (variant) {
        case ButtonVariant.buy:
          surface = ObsidianSurface.trade(
            light: AppColors.tradingGreenLight,
            dark: AppColors.tradingGreenDark,
            radius: r,
          );
          fg = const Color(0xFF04140D);
        case ButtonVariant.sell:
          surface = ObsidianSurface.trade(
            light: AppColors.tradingRedLight,
            dark: AppColors.tradingRedDark,
            radius: r,
          );
          fg = AppColors.white;
        case ButtonVariant.outline:
          surface = ObsidianSurface.dark.copyWith(
            radius: r,
            ring: AppColors.goldBorder,
          );
          fg = AppColors.textPrimary;
        case ButtonVariant.brand:
        case ButtonVariant.gold:
          surface = ObsidianSurface.primaryButton.copyWith(radius: r);
          fg = AppColors.goldTextOn;
      }
    }

    return ObsidianPressable(
      onTap: isDisabled ? null : onPressed,
      child: CustomPaint(
        painter: ObsidianSurfacePainter(surface),
        child: SizedBox(
          height: height,
          width: fullWidth ? double.infinity : null,
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: fullWidth ? 0 : 20),
            child: Center(
              widthFactor: fullWidth ? null : 1,
              child: isLoading
                  ? SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: fg),
                    )
                  : Row(
                      mainAxisSize:
                          fullWidth ? MainAxisSize.max : MainAxisSize.min,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        if (icon != null) ...[
                          Icon(icon, color: fg, size: 18),
                          const SizedBox(width: 8),
                        ],
                        Flexible(
                          child: Text(
                            text,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppFonts.ui(
                              color: fg,
                              fontSize: 15,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        ),
      ),
    );
  }

  LinearGradient? _gradient(AccentProvider accent) {
    switch (variant) {
      case ButtonVariant.buy:
        return AppGradients.buy;
      case ButtonVariant.sell:
        return AppGradients.sell;
      case ButtonVariant.outline:
        return null;
      case ButtonVariant.brand:
      case ButtonVariant.gold:
        return accent.goldGradient;
    }
  }

  Color _textColor(AccentProvider accent) {
    switch (variant) {
      case ButtonVariant.outline:
        return accent.g2;
      case ButtonVariant.brand:
      case ButtonVariant.gold:
        return AppColors.goldTextOn; // dark text on gold
      case ButtonVariant.buy:
      case ButtonVariant.sell:
        return AppColors.white;
    }
  }

  Color _glowColor(AccentProvider accent) {
    switch (variant) {
      case ButtonVariant.buy:
        return AppColors.tradingGreen;
      case ButtonVariant.sell:
        return AppColors.tradingRed;
      case ButtonVariant.outline:
      case ButtonVariant.brand:
      case ButtonVariant.gold:
        return accent.goldGlow;
    }
  }
}
