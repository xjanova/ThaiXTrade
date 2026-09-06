/// TPIX TRADE — Design System Colors
///
/// เดิมเป็น `static const Color` ตายตัว 53 ตัว ตอนนี้เป็น **getter ที่อ่านจาก
/// ชุดสีที่เลือกอยู่** เพื่อให้สลับธีมได้ทั้งแอปโดยไม่ต้องแตะจุดเรียกใช้
/// ทั้ง 1,161 จุด — ชื่อทุกตัวเหมือนเดิมเป๊ะ โค้ดเดิมจึงไม่ต้องแก้
///
/// ทำไมเป็น getter ไม่ใช่ตัวแปรที่เขียนทับ: ถ้าเก็บเป็น `static Color` แล้ว
/// เขียนทับตอนสลับธีม ของที่ derive ไปแล้ว (เช่น gradient ที่เป็น static final)
/// จะค้างค่าเก่าไว้ทั้งที่ธีมเปลี่ยนแล้ว — getter อ่านสดทุกครั้งจึงไม่มีทางค้าง
///
/// NOTE: legacy token names (brandCyan/brandPurple/glowCyan…) ยังอยู่ครบเพื่อ
/// ความเข้ากันได้ย้อนหลัง แต่ resolve ไปที่โทนเน้นของชุดสีปัจจุบัน
///
/// Developed by Xman Studio

import 'dart:ui';

/// ชุดสีหนึ่งชุด — ครบทุกโทเคนที่แอปใช้
///
/// เก็บเฉพาะ "สีต้นทาง" ส่วนที่เป็นนามแฝง (brandCyan → accentMid ฯลฯ)
/// ไปคำนวณเอาที่ [AppColors] จะได้ไม่ต้องกรอกซ้ำทุกชุด
class TradePalette {
  /// คีย์ที่ใช้บันทึกลง SharedPreferences — **ห้ามเปลี่ยนหลังปล่อยแล้ว**
  /// ไม่งั้นเครื่องที่เลือกธีมนี้ไว้จะอ่านไม่เจอแล้วตกกลับไปชุดปริยายเงียบ ๆ
  final String id;

  final String nameTh;
  final String nameEn;
  final String taglineTh;
  final String taglineEn;

  /// ใช้ตั้ง Brightness ของ ThemeData + สีไอคอนแถบสถานะ
  final bool isDark;

  // ── พื้นหลัง ───────────────────────────────────
  final Color bgPrimary;
  final Color bgSecondary;
  final Color bgTertiary;
  final Color bgGradMid;
  final Color bgGradBottom;
  final Color bgCard;
  final Color bgCardBorder;
  final Color bgElevated;
  final Color bgInput;
  final Color bgInputStrong;
  final Color bgOverlay;

  // ── พื้นการ์ดโลหะ ──────────────────────────────
  final Color cardBaseTop;
  final Color cardBaseMid;
  final Color cardBaseBottom;
  final Color cardSubtleTop;
  final Color cardSubtleBottom;
  final Color sheen;
  final Color insetHighlight;

  // ── โทนเน้น (ทองของธีมเดิม / เงินของ XP) ────────
  final Color accentHi;
  final Color accentMid;
  final Color accentDeep;
  final Color accentBorder;
  final Color accentGlow;
  final Color accentTint;

  /// สีตัวอักษรบนปุ่มที่ถมด้วยโทนเน้น
  final Color accentTextOn;

  // ── สีราคาขึ้น/ลง ──────────────────────────────
  final Color tradingGreen;
  final Color tradingGreenLight;
  final Color tradingGreenDark;
  final Color tradingGreenBg;
  final Color tradingRed;
  final Color tradingRedLight;
  final Color tradingRedDark;
  final Color tradingRedBg;

  // ── ตัวอักษร ───────────────────────────────────
  final Color textPrimary;
  final Color textSecondary;
  final Color textTertiary;
  final Color textDisabled;

  // ── เบ็ดเตล็ด ──────────────────────────────────
  final Color divider;

  const TradePalette({
    required this.id,
    required this.nameTh,
    required this.nameEn,
    required this.taglineTh,
    required this.taglineEn,
    required this.isDark,
    required this.bgPrimary,
    required this.bgSecondary,
    required this.bgTertiary,
    required this.bgGradMid,
    required this.bgGradBottom,
    required this.bgCard,
    required this.bgCardBorder,
    required this.bgElevated,
    required this.bgInput,
    required this.bgInputStrong,
    required this.bgOverlay,
    required this.cardBaseTop,
    required this.cardBaseMid,
    required this.cardBaseBottom,
    required this.cardSubtleTop,
    required this.cardSubtleBottom,
    required this.sheen,
    required this.insetHighlight,
    required this.accentHi,
    required this.accentMid,
    required this.accentDeep,
    required this.accentBorder,
    required this.accentGlow,
    required this.accentTint,
    required this.accentTextOn,
    required this.tradingGreen,
    required this.tradingGreenLight,
    required this.tradingGreenDark,
    required this.tradingGreenBg,
    required this.tradingRed,
    required this.tradingRedLight,
    required this.tradingRedDark,
    required this.tradingRedBg,
    required this.textPrimary,
    required this.textSecondary,
    required this.textTertiary,
    required this.textDisabled,
    required this.divider,
  });
}

/// ── ชุดเดิม: "Luxury Dark / Gilded Metal" ─────────────────────
/// เทากันเมทัล + ทองแชมเปญ · เขียว/แดง สงวนไว้สำหรับราคาขึ้น/ลงเท่านั้น
const TradePalette kClassicPalette = TradePalette(
  id: 'classic',
  nameTh: 'โลหะทอง',
  nameEn: 'Gilded Metal',
  taglineTh: 'กันเมทัลเข้ม ขอบทองแชมเปญ',
  taglineEn: 'Gunmetal dark · Champagne edges',
  isDark: true,

  bgPrimary: Color(0xFF0C0D11),
  bgSecondary: Color(0xFF181A22),
  bgTertiary: Color(0xFF20232E),
  bgGradMid: Color(0xFF0C0D11),
  bgGradBottom: Color(0xFF0E0F14),
  bgCard: Color(0xCC0E0F14),
  bgCardBorder: Color(0x0FFFFFFF),
  bgElevated: Color(0xF2181A22),
  bgInput: Color(0x40000000),
  bgInputStrong: Color(0x4D000000),
  bgOverlay: Color(0xB3000000),

  cardBaseTop: Color(0x14FFFFFF),
  cardBaseMid: Color(0x04FFFFFF),
  cardBaseBottom: Color(0x47000000),
  cardSubtleTop: Color(0x0CFFFFFF),
  cardSubtleBottom: Color(0x33000000),
  sheen: Color(0x1FFFFFFF),
  insetHighlight: Color(0x0FFFFFFF),

  accentHi: Color(0xFFFCEBB8),
  accentMid: Color(0xFFD4AF37),
  accentDeep: Color(0xFF9C7A1E),
  accentBorder: Color(0x57D4AF37),
  accentGlow: Color(0x80F0D278),
  accentTint: Color(0x1FD4AF37),
  accentTextOn: Color(0xFF1A160A),

  tradingGreen: Color(0xFF4ED9A4),
  tradingGreenLight: Color(0xFF62E6B4),
  tradingGreenDark: Color(0xFF34C98E),
  tradingGreenBg: Color(0x1F4ED9A4),
  tradingRed: Color(0xFFFF6B7A),
  tradingRedLight: Color(0xFFFF8A95),
  tradingRedDark: Color(0xFFE5495A),
  tradingRedBg: Color(0x1FFF6B7A),

  textPrimary: Color(0xFFF3F1EA),
  textSecondary: Color(0xFF8E897C),
  textTertiary: Color(0x6BF3F1EA),
  textDisabled: Color(0x40F3F1EA),

  divider: Color(0x0DFFFFFF),
);

/// ── ชุดใหม่: "XP Silver" ──────────────────────────────────────
/// โครเมียมเงิน ขอบนูน 3 มิติ ฟ้าไฮไลต์ — พื้นสว่าง
///
/// 🔴 เขียว/แดงต้องเข้มกว่าชุดมืด: มิ้นต์ #4ED9A4 กับแซลมอน #FF6B7A ถูกจูน
/// มาให้เรืองบนพื้นดำ พอวางบนเงินสว่างมันจางจนอ่านทิศราคาไม่ออก ซึ่งใน
/// แอปเทรดคือความผิดพลาดที่แพงที่สุด จึงเปลี่ยนเป็นเขียว/แดงเข้มสำหรับพื้นสว่าง
const TradePalette kXpSilverPalette = TradePalette(
  id: 'xp_silver',
  nameTh: 'เงินคลาสสิก',
  nameEn: 'XP Silver',
  taglineTh: 'โครเมียมเงิน ขอบนูน 3 มิติ',
  taglineEn: 'Silver chrome · 3D bevels',
  isDark: false,

  bgPrimary: Color(0xFFF1F1F5),
  bgSecondary: Color(0xFFE8E8F0),
  bgTertiary: Color(0xFFDFDFEA),
  bgGradMid: Color(0xFFF4F4F8),
  bgGradBottom: Color(0xFFE9E9F1),
  bgCard: Color(0xFAFFFFFF),
  bgCardBorder: Color(0x33000000),
  bgElevated: Color(0xFAF7F7FB),
  bgInput: Color(0xFFFFFFFF),
  bgInputStrong: Color(0xFFFCFCFF),
  bgOverlay: Color(0x8C1A1A24),

  // การ์ดในธีมนี้เป็นแผ่นโลหะทึบ ไม่ใช่กระจกฝ้า — ไล่จากสว่างบนลงเงาล่าง
  cardBaseTop: Color(0xFFFDFDFF),
  cardBaseMid: Color(0xFFE6E6EF),
  cardBaseBottom: Color(0xFFCFCFDD),
  cardSubtleTop: Color(0xFFFAFAFE),
  cardSubtleBottom: Color(0xFFE4E4EE),
  sheen: Color(0x66FFFFFF),
  insetHighlight: Color(0xCCFFFFFF),

  // โทนเน้นคือฟ้าไฮไลต์ของ XP ไม่ใช่เงิน — เงินเป็นสีของกรอบ ถ้าเอามาเป็น
  // สีเน้นด้วย ของสำคัญจะจมหายไปกับพื้นหลังที่เป็นเงินเหมือนกัน
  accentHi: Color(0xFF6E9BE8),
  accentMid: Color(0xFF316AC5),
  accentDeep: Color(0xFF1C4B99),
  accentBorder: Color(0x66316AC5),
  accentGlow: Color(0x40316AC5),
  accentTint: Color(0x1F316AC5),
  accentTextOn: Color(0xFFFFFFFF),

  tradingGreen: Color(0xFF12874E),
  tradingGreenLight: Color(0xFF1BA363),
  tradingGreenDark: Color(0xFF0C6B3C),
  tradingGreenBg: Color(0x1F12874E),
  tradingRed: Color(0xFFC4342A),
  tradingRedLight: Color(0xFFD9564C),
  tradingRedDark: Color(0xFF9C2820),
  tradingRedBg: Color(0x1FC4342A),

  textPrimary: Color(0xFF14141C),
  textSecondary: Color(0xFF4A4A5E),
  textTertiary: Color(0x9914141C),
  textDisabled: Color(0x5914141C),

  divider: Color(0x1F000000),
);

/// ชุดสีทั้งหมดที่เลือกได้ — เพิ่มชุดใหม่ที่นี่แล้วหน้าตั้งค่าจะขึ้นเอง
const List<TradePalette> kTradePalettes = [
  kClassicPalette,
  kXpSilverPalette,
];

TradePalette paletteFromId(String? id) => kTradePalettes.firstWhere(
      (p) => p.id == id,
      orElse: () => kClassicPalette,
    );

/// จุดเดียวที่ทั้งแอปอ่านสี — ชื่อทุกตัวเหมือนตอนเป็น const ทุกประการ
class AppColors {
  AppColors._();

  static TradePalette _palette = kClassicPalette;

  /// ชุดสีที่ใช้อยู่ — อ่านได้ทั่วแอป (เช่นเช็ก isDark)
  static TradePalette get palette => _palette;

  /// สลับชุดสี — เรียกจาก ThemeProvider เท่านั้น
  /// ต้อง rebuild ทั้งต้นไม้ widget หลังเรียก ไม่งั้นของที่วาดไปแล้วไม่เปลี่ยน
  static void apply(TradePalette p) => _palette = p;

  // ── พื้นหลัง ───────────────────────────────────
  static Color get bgPrimary => _palette.bgPrimary;
  static Color get bgSecondary => _palette.bgSecondary;
  static Color get bgTertiary => _palette.bgTertiary;
  static Color get bgGradMid => _palette.bgGradMid;
  static Color get bgGradBottom => _palette.bgGradBottom;
  static Color get bgCard => _palette.bgCard;
  static Color get bgCardBorder => _palette.bgCardBorder;
  static Color get bgElevated => _palette.bgElevated;
  static Color get bgInput => _palette.bgInput;
  static Color get bgInputStrong => _palette.bgInputStrong;
  static Color get bgOverlay => _palette.bgOverlay;

  // ── พื้นการ์ดโลหะ ──────────────────────────────
  static Color get cardBaseTop => _palette.cardBaseTop;
  static Color get cardBaseMid => _palette.cardBaseMid;
  static Color get cardBaseBottom => _palette.cardBaseBottom;
  static Color get cardSubtleTop => _palette.cardSubtleTop;
  static Color get cardSubtleBottom => _palette.cardSubtleBottom;
  static Color get sheen => _palette.sheen;
  static Color get insetHighlight => _palette.insetHighlight;

  // ── โทนเน้น ────────────────────────────────────
  static Color get gold1 => _palette.accentHi;
  static Color get gold2 => _palette.accentMid;
  static Color get gold3 => _palette.accentDeep;
  static Color get goldBorder => _palette.accentBorder;
  static Color get goldGlow => _palette.accentGlow;
  static Color get goldTint => _palette.accentTint;
  static Color get gold => _palette.accentMid;
  static Color get goldTextOn => _palette.accentTextOn;

  // ── นามแฝงเดิม → โทนเน้น ───────────────────────
  static Color get brandCyan => _palette.accentMid;
  static Color get brandCyanLight => _palette.accentHi;
  static Color get brandCyanDark => _palette.accentDeep;
  static Color get brandPurple => _palette.accentMid;
  static Color get brandPurpleLight => _palette.accentHi;
  static Color get brandPurpleDark => _palette.accentDeep;
  static Color get brandWarm => _palette.accentMid;

  // ── ราคาขึ้น/ลง ────────────────────────────────
  static Color get tradingGreen => _palette.tradingGreen;
  static Color get tradingGreenLight => _palette.tradingGreenLight;
  static Color get tradingGreenDark => _palette.tradingGreenDark;
  static Color get tradingGreenBg => _palette.tradingGreenBg;
  static Color get tradingRed => _palette.tradingRed;
  static Color get tradingRedLight => _palette.tradingRedLight;
  static Color get tradingRedDark => _palette.tradingRedDark;
  static Color get tradingRedBg => _palette.tradingRedBg;
  static Color get tradingYellow => _palette.accentMid;

  // ── ตัวอักษร ───────────────────────────────────
  static Color get textPrimary => _palette.textPrimary;
  static Color get textSecondary => _palette.textSecondary;
  static Color get textTertiary => _palette.textTertiary;
  static Color get textDisabled => _palette.textDisabled;

  // ── เบ็ดเตล็ด ──────────────────────────────────
  static Color get divider => _palette.divider;
  static const Color white = Color(0xFFFFFFFF);
  static const Color black = Color(0xFF000000);

  // ── เรืองแสง (นามแฝงเดิม) ──────────────────────
  static Color get glowCyan => _palette.accentGlow;
  static Color get glowPurple => _palette.accentGlow;
  static Color get glowGold => _palette.accentGlow;
  static Color get glowGreen => _palette.tradingGreenBg;
  static Color get glowRed => _palette.tradingRedBg;
}
