/// TPIX TRADE — Gradient Button
/// ปุ่มไล่เฉดสี brand (cyan → purple) + Buy/Sell variants
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/gradients.dart';

enum ButtonVariant { brand, buy, sell, outline }

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
    this.variant = ButtonVariant.brand,
    this.isLoading = false,
    this.icon,
    this.height = 48,
    this.borderRadius = 16,
    this.fullWidth = true,
  });

  @override
  Widget build(BuildContext context) {
    final gradient = _gradient;
    final isDisabled = onPressed == null || isLoading;

    Widget button = Container(
      height: height,
      width: fullWidth ? double.infinity : null,
      decoration: BoxDecoration(
        gradient: isDisabled ? null : gradient,
        color: isDisabled ? AppColors.bgTertiary : null,
        borderRadius: BorderRadius.circular(borderRadius),
        border: variant == ButtonVariant.outline
            ? Border.all(color: AppColors.brandCyan, width: 1.5)
            : null,
        boxShadow: isDisabled
            ? null
            : [
                BoxShadow(
                  color: _glowColor.withValues(alpha: 0.3),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
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
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : Row(
                    mainAxisSize:
                        fullWidth ? MainAxisSize.max : MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (icon != null) ...[
                        Icon(icon, color: _textColor, size: 18),
                        const SizedBox(width: 8),
                      ],
                      Text(
                        text,
                        style: TextStyle(
                          color: _textColor,
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

  LinearGradient? get _gradient {
    switch (variant) {
      case ButtonVariant.buy:
        return AppGradients.buy;
      case ButtonVariant.sell:
        return AppGradients.sell;
      case ButtonVariant.outline:
        return null;
      case ButtonVariant.brand:
        return AppGradients.brand;
    }
  }

  Color get _textColor {
    switch (variant) {
      case ButtonVariant.outline:
        return AppColors.brandCyan;
      case ButtonVariant.brand:
      case ButtonVariant.buy:
      case ButtonVariant.sell:
        return Colors.white;
    }
  }

  Color get _glowColor {
    switch (variant) {
      case ButtonVariant.buy:
        return AppColors.tradingGreen;
      case ButtonVariant.sell:
        return AppColors.tradingRed;
      case ButtonVariant.outline:
        return AppColors.brandCyan;
      case ButtonVariant.brand:
        return AppColors.brandCyan;
    }
  }
}
