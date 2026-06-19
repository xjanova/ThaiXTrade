/// TPIX TRADE — Gradient Definitions
/// "Luxury Dark / Gilded Metal" — gold gradients + gunmetal background.
///
/// Legacy names (brand/portfolioCard) kept but now resolve to GOLD so
/// existing widgets re-skin automatically.
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'app_colors.dart';

class AppGradients {
  AppGradients._();

  /// Champagne gold gradient — 135deg #FCEBB8 → #D4AF37 → #9C7A1E
  /// (default tone; AccentProvider provides the runtime-switchable version)
  static const LinearGradient gold = LinearGradient(
    colors: [AppColors.gold1, AppColors.gold2, AppColors.gold3],
    stops: [0.0, 0.42, 1.0],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Brand gradient (LEGACY) → now gold
  static const LinearGradient brand = gold;

  /// Brand alt (LEGACY) → softer gold (highlight → mid)
  static const LinearGradient brandAlt = LinearGradient(
    colors: [AppColors.gold1, AppColors.gold2],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  /// Portfolio / hero card accent (LEGACY) → gold
  static const LinearGradient portfolioCard = gold;

  /// Metallic glass card fill — base hero card
  /// linear(158deg white8% → white1.5% @44% → black28%)
  static const LinearGradient glassCard = LinearGradient(
    colors: [
      AppColors.cardBaseTop,
      AppColors.cardBaseMid,
      AppColors.cardBaseBottom,
    ],
    stops: [0.0, 0.44, 1.0],
    begin: Alignment(-0.6, -1.0), // ~158deg
    end: Alignment(0.6, 1.0),
  );

  /// Subtle list-row fill — linear(160deg white4.5% → black20%)
  static const LinearGradient cardSubtle = LinearGradient(
    colors: [AppColors.cardSubtleTop, AppColors.cardSubtleBottom],
    begin: Alignment(-0.6, -1.0),
    end: Alignment(0.6, 1.0),
  );

  /// Diagonal metal sheen overlay (place absolutely on hero cards)
  static const LinearGradient metalSheen = LinearGradient(
    colors: [AppColors.sheen, Color(0x00FFFFFF)],
    stops: [0.0, 0.6],
    begin: Alignment(-1.0, -0.4),
    end: Alignment(1.0, 0.4),
  );

  // ── App background (use via AppBackground widget) ──
  /// Base gunmetal gradient — linear(168deg #181a22 → #0c0d11 @52% → #0e0f14)
  static const LinearGradient appBackgroundBase = LinearGradient(
    colors: [
      AppColors.bgSecondary,
      AppColors.bgGradMid,
      AppColors.bgGradBottom,
    ],
    stops: [0.0, 0.52, 1.0],
    begin: Alignment(-0.1, -1.0), // ~168deg
    end: Alignment(0.1, 1.0),
  );

  /// Gold halo at top — radial(120% 70% at 50% -12%, gold12% → transparent @56%)
  static const RadialGradient appBackgroundGlow = RadialGradient(
    center: Alignment(0.0, -1.24), // 50% / -12%
    radius: 1.2,
    colors: [AppColors.goldTint, Color(0x00D4AF37)],
    stops: [0.0, 0.56],
  );

  /// Dark background gradient (LEGACY) → gunmetal base
  static const LinearGradient darkBg = appBackgroundBase;

  /// Buy gradient — green, linear(160deg #62e6b4 → #34c98e)
  static const LinearGradient buy = LinearGradient(
    colors: [AppColors.tradingGreenLight, AppColors.tradingGreenDark],
    begin: Alignment(-0.4, -1.0),
    end: Alignment(0.4, 1.0),
  );

  /// Sell gradient — red
  static const LinearGradient sell = LinearGradient(
    colors: [AppColors.tradingRedLight, AppColors.tradingRedDark],
    begin: Alignment(-0.4, -1.0),
    end: Alignment(0.4, 1.0),
  );

  /// Subtle brand overlay (LEGACY) → gold tint wash
  static const LinearGradient brandOverlay = LinearGradient(
    colors: [AppColors.goldTint, Color(0x0AD4AF37)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Shimmer / skeleton loading (gunmetal)
  static const LinearGradient shimmer = LinearGradient(
    colors: [
      AppColors.bgTertiary,
      Color(0xFF2A2E3C),
      AppColors.bgTertiary,
    ],
    stops: [0.0, 0.5, 1.0],
    begin: Alignment(-1.5, 0),
    end: Alignment(1.5, 0),
  );
}
