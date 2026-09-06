/// TPIX TRADE — App Theme
/// ธีมหลักของแอป: Dark glass morphism
/// ใช้ Inter สำหรับ UI, JetBrains Mono สำหรับตัวเลข
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'app_colors.dart';

class AppTheme {
  AppTheme._();

  // ── Spacing ────────────────────────────────────
  static const double spacingXs = 4;
  static const double spacingSm = 8;
  static const double spacingMd = 12;
  static const double spacingLg = 16;
  static const double spacingXl = 20;
  static const double spacing2xl = 24;
  static const double spacing3xl = 32;

  // ── Radius ─────────────────────────────────────
  static const double radiusSm = 8;
  static const double radiusMd = 12;
  static const double radiusLg = 16;
  static const double radiusXl = 20;
  static const double radius2xl = 24;
  static const double radiusHero = 22; // hero / metallic cards
  static const double radiusFull = 999;

  // ── Signature gilded edge ──────────────────────
  static const double goldBorderWidth = 1.6;

  /// ธีมของแอป — สีมาจากชุดที่ผู้ใช้เลือกไว้ใน AppColors
  ///
  /// ยังชื่อ darkTheme เพื่อไม่ให้จุดเรียกใช้ต้องแก้ แต่ไม่ได้มืดเสมอไปแล้ว
  /// ทุกอย่างข้างในอ่านผ่าน getter จึงได้สีของชุดปัจจุบันทุกครั้งที่ build
  static ThemeData get darkTheme {
    final isDark = AppColors.palette.isDark;
    final textTheme = GoogleFonts.interTextTheme(
      TextTheme(
        // Headings
        headlineLarge: TextStyle(
          fontSize: 32, fontWeight: FontWeight.w700,
          color: AppColors.textPrimary, letterSpacing: -0.5,
        ),
        headlineMedium: TextStyle(
          fontSize: 24, fontWeight: FontWeight.w700,
          color: AppColors.textPrimary, letterSpacing: -0.3,
        ),
        headlineSmall: TextStyle(
          fontSize: 20, fontWeight: FontWeight.w600,
          color: AppColors.textPrimary,
        ),
        // Titles
        titleLarge: TextStyle(
          fontSize: 18, fontWeight: FontWeight.w600,
          color: AppColors.textPrimary,
        ),
        titleMedium: TextStyle(
          fontSize: 16, fontWeight: FontWeight.w500,
          color: AppColors.textPrimary,
        ),
        titleSmall: TextStyle(
          fontSize: 14, fontWeight: FontWeight.w600,
          color: AppColors.textPrimary,
        ),
        // Body
        bodyLarge: TextStyle(
          fontSize: 16, fontWeight: FontWeight.w400,
          color: AppColors.textSecondary,
        ),
        bodyMedium: TextStyle(
          fontSize: 14, fontWeight: FontWeight.w400,
          color: AppColors.textSecondary,
        ),
        bodySmall: TextStyle(
          fontSize: 12, fontWeight: FontWeight.w400,
          color: AppColors.textTertiary,
        ),
        // Labels
        labelLarge: TextStyle(
          fontSize: 14, fontWeight: FontWeight.w600,
          color: AppColors.textPrimary,
        ),
        labelMedium: TextStyle(
          fontSize: 12, fontWeight: FontWeight.w500,
          color: AppColors.textSecondary,
        ),
        labelSmall: TextStyle(
          fontSize: 10, fontWeight: FontWeight.w600,
          color: AppColors.textTertiary, letterSpacing: 0.5,
        ),
      ),
    );

    return ThemeData(
      brightness: isDark ? Brightness.dark : Brightness.light,
      useMaterial3: true,
      scaffoldBackgroundColor: AppColors.bgPrimary,
      primaryColor: AppColors.gold2,
      colorScheme: (isDark ? const ColorScheme.dark() : const ColorScheme.light())
          .copyWith(
        primary: AppColors.gold2,
        secondary: AppColors.gold3,
        surface: AppColors.bgSecondary,
        error: AppColors.tradingRed,
        onPrimary: AppColors.goldTextOn,
        onSecondary: AppColors.goldTextOn,
        onSurface: AppColors.textPrimary,
        onError: AppColors.white,
      ),
      textTheme: textTheme,
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        elevation: 0,
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          // ไอคอนแถบสถานะต้องตรงข้ามกับพื้น ไม่งั้นบนพื้นสว่างจะขาวบนขาว มองไม่เห็น
          statusBarIconBrightness: isDark ? Brightness.light : Brightness.dark,
          statusBarBrightness: isDark ? Brightness.dark : Brightness.light,
          systemNavigationBarIconBrightness:
              isDark ? Brightness.light : Brightness.dark,
          systemNavigationBarColor: AppColors.bgPrimary,
        ),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: Colors.transparent,
        selectedItemColor: AppColors.brandCyan,
        unselectedItemColor: AppColors.textTertiary,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
      ),
      cardTheme: CardThemeData(
        color: AppColors.bgCard,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          side: BorderSide(color: AppColors.bgCardBorder),
        ),
      ),
      dividerTheme: DividerThemeData(
        color: AppColors.divider,
        thickness: 1,
        space: 0,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.bgInput,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          borderSide: BorderSide(color: AppColors.bgCardBorder),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          borderSide: BorderSide(color: AppColors.bgCardBorder),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          borderSide: BorderSide(color: AppColors.brandCyan, width: 1.5),
        ),
        hintStyle: TextStyle(color: AppColors.textDisabled, fontSize: 14),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.gold2,
          foregroundColor: AppColors.goldTextOn,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          // ธีมโลหะใช้ทรงแคปซูลตามปุ่ม Start ของ XP — ลายเซ็นของปุ่มนั้น
          // คือรูปทรงกับผิวเจล ไม่ใช่สีเขียว สีจึงยังอิงโทนของเราเอง
          shape: AppColors.palette.metal
              ? const StadiumBorder()
              : RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(radiusLg),
                ),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.bgElevated,
        contentTextStyle: TextStyle(color: AppColors.textPrimary, fontSize: 14),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusMd),
        ),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  /// Mono text style — สำหรับตัวเลข, ราคา, address
  static TextStyle mono({
    double fontSize = 15,
    FontWeight fontWeight = FontWeight.w600,
    Color? color,
  }) {
    return GoogleFonts.jetBrainsMono(
      fontSize: fontSize,
      fontWeight: fontWeight,
      // ค่าปริยายย้ายมาอยู่ตรงนี้ เพราะพารามิเตอร์รับค่าที่ไม่ใช่ const ไม่ได้
      // ปล่อย null ผ่านไปไม่ได้ — ตัวอักษร 107 จุดที่เรียก mono() โดยไม่ส่งสีจะเสียสี
      color: color ?? AppColors.textPrimary,
    );
  }

  /// Price text style — สีตาม +/-
  static TextStyle priceChange({
    required double change,
    double fontSize = 13,
    FontWeight fontWeight = FontWeight.w600,
  }) {
    final color = change >= 0 ? AppColors.tradingGreen : AppColors.tradingRed;
    return GoogleFonts.jetBrainsMono(
      fontSize: fontSize,
      fontWeight: fontWeight,
      color: color,
    );
  }
}
