/// TPIX TRADE — Design System Colors
/// "Luxury Dark / Gilded Metal" — gunmetal-dark glass + champagne-gold edges.
/// สีหลัก: เทากันเมทัล + ทอง. เขียว/แดง สงวนไว้สำหรับราคาขึ้น/ลงเท่านั้น.
///
/// NOTE: legacy token names (brandCyan/brandPurple/glowCyan…) are KEPT for
/// backward compatibility but now resolve to GOLD values, so every existing
/// screen re-skins to the metal look without code changes. New code should
/// prefer the gold* tokens or the runtime AccentProvider for switchable tones.
///
/// Developed by Xman Studio

import 'dart:ui';

class AppColors {
  AppColors._();

  // ── Background Layers (gunmetal) ───────────────
  // App background gradient: linear(168deg #181a22 → #0c0d11 → #0e0f14)
  static const Color bgPrimary = Color(0xFF0C0D11); // deep base / scaffold
  static const Color bgSecondary = Color(0xFF181A22); // gunmetal top
  static const Color bgTertiary = Color(0xFF20232E); // elevated gunmetal
  static const Color bgGradMid = Color(0xFF0C0D11);
  static const Color bgGradBottom = Color(0xFF0E0F14);
  static const Color bgCard = Color(0xCC0E0F14); // translucent dark card
  static const Color bgCardBorder = Color(0x0FFFFFFF); // rgba(255,255,255,0.06)
  static const Color bgElevated = Color(0xF2181A22); // rgba(24,26,34,0.95)
  static const Color bgInput = Color(0x40000000); // rgba(0,0,0,0.25) field fill
  static const Color bgInputStrong = Color(0x4D000000); // rgba(0,0,0,0.30)
  static const Color bgOverlay = Color(0xB3000000); // rgba(0,0,0,0.7)

  // ── Metallic card fills (referenced by AppGradients) ──
  static const Color cardBaseTop = Color(0x14FFFFFF); // white 8%
  static const Color cardBaseMid = Color(0x04FFFFFF); // white 1.5%
  static const Color cardBaseBottom = Color(0x47000000); // black 28%
  static const Color cardSubtleTop = Color(0x0CFFFFFF); // white 4.5%
  static const Color cardSubtleBottom = Color(0x33000000); // black 20%
  static const Color sheen = Color(0x1FFFFFFF); // diagonal metal sheen ~12%
  static const Color insetHighlight = Color(0x0FFFFFFF); // top sheen 6%

  // ── Gold accent (Champagne Gold — default tone) ─
  static const Color gold1 = Color(0xFFFCEBB8); // highlight
  static const Color gold2 = Color(0xFFD4AF37); // mid / icon stroke
  static const Color gold3 = Color(0xFF9C7A1E); // deep
  static const Color goldBorder = Color(0x57D4AF37); // rgba(212,175,55,0.34)
  static const Color goldGlow = Color(0x80F0D278); // rgba(240,210,120,0.5)
  static const Color goldTint = Color(0x1FD4AF37); // 12% gold wash (tiles)

  // ── Brand Colors (LEGACY aliases → gold) ───────
  static const Color brandCyan = gold2;
  static const Color brandCyanLight = gold1;
  static const Color brandCyanDark = gold3;
  static const Color brandPurple = gold2;
  static const Color brandPurpleLight = gold1;
  static const Color brandPurpleDark = gold3;
  static const Color brandWarm = gold2;

  // ── Trading Colors (the ONLY non-metal hues) ───
  static const Color tradingGreen = Color(0xFF4ED9A4); // --up
  static const Color tradingGreenLight = Color(0xFF62E6B4); // buy grad start
  static const Color tradingGreenDark = Color(0xFF34C98E); // buy grad end
  static const Color tradingGreenBg = Color(0x1F4ED9A4); // 12%
  static const Color tradingRed = Color(0xFFFF6B7A); // --down
  static const Color tradingRedLight = Color(0xFFFF8A95);
  static const Color tradingRedDark = Color(0xFFE5495A);
  static const Color tradingRedBg = Color(0x1FFF6B7A); // 12%
  static const Color tradingYellow = gold2;

  // ── Text Colors (warm white) ───────────────────
  static const Color textPrimary = Color(0xFFF3F1EA); // --txt warm white
  static const Color textSecondary = Color(0xFF8E897C); // --mut
  static const Color textTertiary = Color(0x6BF3F1EA); // --faint 42%
  static const Color textDisabled = Color(0x40F3F1EA); // 25%

  // ── Misc ───────────────────────────────────────
  static const Color divider = Color(0x0DFFFFFF); // ~5%
  static const Color white = Color(0xFFFFFFFF);
  static const Color black = Color(0xFF000000);
  static const Color goldTextOn = Color(0xFF1A160A); // dark text on gold CTAs

  // ── Glow / Shadow (LEGACY aliases → gold) ──────
  static const Color glowCyan = Color(0x4DD4AF37); // gold glow 30%
  static const Color glowPurple = Color(0x4DD4AF37);
  static const Color glowGold = Color(0x4DD4AF37);
  static const Color glowGreen = Color(0x4D4ED9A4);
  static const Color glowRed = Color(0x4DFF6B7A);
}
