/// TPIX TRADE — Gradient Definitions
/// ไล่เฉดสีทั้งหมดของแอป
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'app_colors.dart';

class AppGradients {
  AppGradients._();

  /// Brand gradient — cyan → purple (ใช้กับปุ่มหลัก, badge)
  static const LinearGradient brand = LinearGradient(
    colors: [AppColors.brandCyan, AppColors.brandPurple],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  /// Brand alt — lighter version
  static const LinearGradient brandAlt = LinearGradient(
    colors: [AppColors.brandCyanLight, AppColors.brandPurpleLight],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  /// Portfolio card — cyan → purple (เว็บใช้ใน hero card)
  static const LinearGradient portfolioCard = LinearGradient(
    colors: [AppColors.brandCyan, AppColors.brandPurple],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Glass card — simulated glass fill (ไม่ใช้ blur จริง เพื่อ performance)
  static const LinearGradient glassCard = LinearGradient(
    colors: [
      Color(0xE60F1629), // rgba(15,22,41,0.9)
      Color(0xB3151C32), // rgba(21,28,50,0.7)
    ],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Dark background gradient
  static const LinearGradient darkBg = LinearGradient(
    colors: [AppColors.bgPrimary, AppColors.bgSecondary],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );

  /// Buy gradient — green
  static const LinearGradient buy = LinearGradient(
    colors: [AppColors.tradingGreen, AppColors.tradingGreenLight],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  /// Sell gradient — red
  static const LinearGradient sell = LinearGradient(
    colors: [AppColors.tradingRed, AppColors.tradingRedLight],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  /// Subtle brand overlay — สำหรับ glass card variant brand
  static const LinearGradient brandOverlay = LinearGradient(
    colors: [
      Color(0x1406B6D4), // cyan 8%
      Color(0x148B5CF6), // purple 8%
    ],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Shimmer / skeleton loading
  static const LinearGradient shimmer = LinearGradient(
    colors: [
      AppColors.bgTertiary,
      Color(0xFF1E2740),
      AppColors.bgTertiary,
    ],
    stops: [0.0, 0.5, 1.0],
    begin: Alignment(-1.5, 0),
    end: Alignment(1.5, 0),
  );
}
