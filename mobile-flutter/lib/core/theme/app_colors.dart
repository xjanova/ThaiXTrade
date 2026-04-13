/// TPIX TRADE — Design System Colors
/// สีทั้งหมดของแอป ตรงกับเว็บ (tailwind) + TPIX Wallet
///
/// Developed by Xman Studio

import 'dart:ui';

class AppColors {
  AppColors._();

  // ── Background Layers ──────────────────────────
  static const Color bgPrimary = Color(0xFF0A0E1A);
  static const Color bgSecondary = Color(0xFF0F1629);
  static const Color bgTertiary = Color(0xFF151C32);
  static const Color bgCard = Color(0xD90F1629); // rgba(15,22,41,0.85)
  static const Color bgCardBorder = Color(0x0FFFFFFF); // rgba(255,255,255,0.06)
  static const Color bgElevated = Color(0xF2151C32); // rgba(21,28,50,0.95)
  static const Color bgInput = Color(0x990A0E1A); // rgba(10,14,26,0.6)
  static const Color bgOverlay = Color(0xB3000000); // rgba(0,0,0,0.7)

  // ── Brand Colors ───────────────────────────────
  static const Color brandCyan = Color(0xFF06B6D4);
  static const Color brandCyanLight = Color(0xFF22D3EE);
  static const Color brandCyanDark = Color(0xFF0891B2);
  static const Color brandPurple = Color(0xFF8B5CF6);
  static const Color brandPurpleLight = Color(0xFFA78BFA);
  static const Color brandPurpleDark = Color(0xFF7C3AED);
  static const Color brandWarm = Color(0xFFF97316); // orange accent

  // ── Trading Colors ─────────────────────────────
  static const Color tradingGreen = Color(0xFF00C853);
  static const Color tradingGreenLight = Color(0xFF69F0AE);
  static const Color tradingGreenDark = Color(0xFF00A844);
  static const Color tradingGreenBg = Color(0x1F00C853); // 12%
  static const Color tradingRed = Color(0xFFFF1744);
  static const Color tradingRedLight = Color(0xFFFF5252);
  static const Color tradingRedDark = Color(0xFFD50000);
  static const Color tradingRedBg = Color(0x1FFF1744); // 12%
  static const Color tradingYellow = Color(0xFFFFD600);

  // ── Text Colors ────────────────────────────────
  static const Color textPrimary = Color(0xFFFFFFFF);
  static const Color textSecondary = Color(0xB3FFFFFF); // 70%
  static const Color textTertiary = Color(0x73FFFFFF); // 45%
  static const Color textDisabled = Color(0x40FFFFFF); // 25%

  // ── Misc ───────────────────────────────────────
  static const Color divider = Color(0x0FFFFFFF); // 6%
  static const Color white = Color(0xFFFFFFFF);
  static const Color black = Color(0xFF000000);

  // ── Glow / Shadow ──────────────────────────────
  static const Color glowCyan = Color(0x4D06B6D4); // 30%
  static const Color glowPurple = Color(0x4D8B5CF6);
  static const Color glowGreen = Color(0x4D00C853);
  static const Color glowRed = Color(0x4DFF1744);
}
