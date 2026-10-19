/// TPIX TRADE — ฟอนต์ตามชุดสี
///
/// เดิมทั้งแอปเรียก `GoogleFonts.inter(...)` / `GoogleFonts.jetBrainsMono(...)`
/// ตรง ๆ 452 จุด สลับฟอนต์ตามธีมไม่ได้ — ตอนนี้ทุกจุดเรียกผ่านที่นี่แทน
/// (พารามิเตอร์ชุดเดียวกับที่จุดเดิมใช้ จึงแทนที่ชื่อฟังก์ชันได้ตรง ๆ)
///
///   ชุด Obsidian → IBM Plex Sans Thai (ตัวหนังสือ) + Space Grotesk (ตัวเลข)
///                  ชุดเดียวกับ TPIX Wallet
///   ชุดอื่น      → Inter + JetBrains Mono เหมือนเดิม
///
/// 🔴 ส่ง fontWeight เข้า GoogleFonts ตรง ๆ เสมอ — ห้าม `.copyWith(fontWeight:)`
///    ไม่งั้นมันไม่โหลดไฟล์น้ำหนักนั้น เครื่องจะทำตัวหนาปลอมจากตัวปกติแทน
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'app_colors.dart';

abstract final class AppFonts {
  /// ตัวหนังสือทั่วไป (แทน GoogleFonts.inter)
  static TextStyle ui({
    double? fontSize,
    FontWeight? fontWeight,
    FontStyle? fontStyle,
    Color? color,
    double? letterSpacing,
    double? height,
  }) {
    if (AppColors.palette.obsidian) {
      return GoogleFonts.ibmPlexSansThai(
        fontSize: fontSize,
        fontWeight: fontWeight,
        fontStyle: fontStyle,
        color: color,
        letterSpacing: letterSpacing,
        height: height,
      );
    }
    return GoogleFonts.inter(
      fontSize: fontSize,
      fontWeight: fontWeight,
      fontStyle: fontStyle,
      color: color,
      letterSpacing: letterSpacing,
      height: height,
    );
  }

  /// ตัวเลข ราคา ยอด และที่อยู่ (แทน GoogleFonts.jetBrainsMono)
  ///
  /// Obsidian บังคับตัวเลขกว้างเท่ากัน — ไม่งั้นราคาในสมุดคำสั่ง/ตารางตลาด
  /// จะส่ายไปมาทุกครั้งที่ตัวเลขเปลี่ยน (Space Grotesk ปกติเป็นตัวเลขกว้างไม่เท่ากัน)
  static TextStyle number({
    double? fontSize,
    FontWeight? fontWeight,
    Color? color,
    double? letterSpacing,
    double? height,
  }) {
    if (AppColors.palette.obsidian) {
      return GoogleFonts.spaceGrotesk(
        fontSize: fontSize,
        fontWeight: fontWeight,
        color: color,
        letterSpacing: letterSpacing,
        height: height,
        fontFeatures: const [FontFeature.tabularFigures()],
      );
    }
    return GoogleFonts.jetBrainsMono(
      fontSize: fontSize,
      fontWeight: fontWeight,
      color: color,
      letterSpacing: letterSpacing,
      height: height,
    );
  }
}
