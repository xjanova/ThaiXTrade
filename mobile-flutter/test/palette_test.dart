/// TPIX TRADE — ชุดสีสลับได้
///
/// เดิม AppColors เป็น `static const Color` ตายตัว 53 ตัว ถูกอ้าง 1,161 จุด
/// ทั่วแอป การทำธีมสลับได้จึงเปลี่ยนมันเป็น getter ที่อ่านจากชุดสีปัจจุบัน
/// แทนที่จะไล่แก้จุดเรียกใช้ทั้งหมด
///
/// เทสต์นี้เฝ้าสิ่งที่พังแล้วผู้ใช้เดือดร้อนจริง ไม่ใช่เฝ้าค่าสีทีละตัว
///
/// Developed by Xman Studio
library;

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/theme/app_colors.dart';
import 'package:tpix_trade/core/theme/gradients.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/providers/theme_provider.dart';

/// อัตราส่วนคอนทราสต์ตามสูตร WCAG 2.x
double _contrast(Color fg, Color bg) {
  double lum(Color c) {
    double channel(double v) =>
        v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();

    return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
  }

  final a = lum(fg) + 0.05;
  final b = lum(bg) + 0.05;
  return a > b ? a / b : b / a;
}

void main() {
  tearDown(() => AppColors.apply(kDefaultPalette));

  group('ชุดสี', () {
    test('ชุดปริยายคือ Obsidian — คนที่ยังไม่เคยเลือก ได้หน้าตาเดียวกับ TPIX Wallet', () {
      expect(AppColors.palette.id, kObsidianPalette.id);
      expect(AppColors.palette.obsidian, isTrue);
      expect(AppColors.bgPrimary, const Color(0xFF07080C));
      expect(AppColors.textPrimary, const Color(0xFFF2F3F5));
    });

    test('ชุดทองเดิมยังเลือกได้ และค่าสีไม่เปลี่ยน — คนที่เลือกไว้ต้องเห็นเหมือนเดิม', () {
      AppColors.apply(paletteFromId('classic'));
      expect(AppColors.palette.obsidian, isFalse);
      expect(AppColors.bgPrimary, const Color(0xFF0C0D11));
      expect(AppColors.gold2, const Color(0xFFD4AF37));
      expect(AppColors.textPrimary, const Color(0xFFF3F1EA));
    });

    test('สลับชุดแล้วสีเปลี่ยนตามจริง', () {
      AppColors.apply(kXpSilverPalette);

      expect(AppColors.bgPrimary, kXpSilverPalette.bgPrimary);
      expect(AppColors.textPrimary, kXpSilverPalette.textPrimary);
      expect(AppColors.gold2, kXpSilverPalette.accentMid);
    });

    test('gradient ต้องไม่ค้างสีของชุดเดิม', () {
      // จุดที่พลาดง่ายที่สุด: ถ้า gradients เป็น static final แทน getter
      // มันจะประเมินครั้งเดียวตอนเข้าถึงครั้งแรกแล้วค้างค่านั้นตลอด
      // ผู้ใช้จะเห็นพื้นหลังธีมใหม่ แต่การ์ดยังเป็นสีธีมเก่า
      final before = AppGradients.gold.colors.first;

      AppColors.apply(kXpSilverPalette);
      final after = AppGradients.gold.colors.first;

      expect(after, isNot(before));
      expect(after, kXpSilverPalette.accentHi);
    });

    test('คีย์ของทุกชุดต้องไม่ซ้ำ และไม่เปลี่ยน', () {
      // คีย์ถูกเขียนลง SharedPreferences — เปลี่ยนแล้วเครื่องที่เลือกไว้
      // จะอ่านไม่เจอ ตกกลับไปชุดปริยายเงียบ ๆ
      final ids = kTradePalettes.map((p) => p.id).toList();
      expect(ids.toSet().length, ids.length);
      expect(ids, containsAll(['obsidian', 'classic', 'xp_silver']));
    });

    test('คีย์ที่ไม่รู้จักหรือ null ตกกลับชุดปริยาย ไม่ crash', () {
      expect(paletteFromId(null).id, kDefaultPalette.id);
      expect(paletteFromId('ไม่มีชุดนี้').id, kDefaultPalette.id);
      expect(paletteFromId('xp_silver').id, kXpSilverPalette.id);
    });
  });

  group('อ่านออกจริงไหม — สำคัญเป็นพิเศษเพราะเป็นแอปเทรด', () {
    for (final p in kTradePalettes) {
      test('${p.nameEn}: สีราคาขึ้น/ลง ต้องตัดกับพื้นพอให้อ่านทิศได้', () {
        // ทิศราคาอ่านผิด = ตัดสินใจผิด นี่คือความผิดพลาดที่แพงที่สุดในแอปนี้
        // 3.0:1 คือเกณฑ์ WCAG ของข้อความขนาดใหญ่/องค์ประกอบกราฟิก
        expect(_contrast(p.tradingGreen, p.bgPrimary), greaterThan(3.0),
            reason: '${p.nameEn}: เขียวจางเกินไปบนพื้นของชุดนี้');
        expect(_contrast(p.tradingRed, p.bgPrimary), greaterThan(3.0),
            reason: '${p.nameEn}: แดงจางเกินไปบนพื้นของชุดนี้');
      });

      test('${p.nameEn}: ตัวอักษรหลักต้องตัดกับพื้น', () {
        expect(_contrast(p.textPrimary, p.bgPrimary), greaterThan(4.5),
            reason: '${p.nameEn}: ตัวอักษรหลักอ่านไม่ออกบนพื้นของชุดนี้');
      });

      test('${p.nameEn}: ตัวอักษรบนปุ่มโทนเน้นต้องตัดกับปุ่ม', () {
        expect(_contrast(p.accentTextOn, p.accentMid), greaterThan(4.5),
            reason: '${p.nameEn}: ตัวหนังสือบนปุ่มหลักอ่านไม่ออก');
      });
    }
  });

  group('Obsidian — ขอบทองทั้งแอปต้องกลายเป็นโครเมียม', () {
    test('AccentProvider คืนโทนโครเมียมในธีม Obsidian แต่จำโทนที่ผู้ใช้เลือกไว้', () async {
      SharedPreferences.setMockInitialValues({'accent_tone': 'rose_gold'});
      final accent = AccentProvider();
      await accent.ready;

      AppColors.apply(kObsidianPalette);
      expect(accent.palette.id, kObsidianChrome.id);
      expect(accent.toneSelectable, isFalse);

      // กลับไปธีมทอง → ได้โทนที่เลือกไว้คืน ไม่ใช่ทองปริยาย
      AppColors.apply(kClassicPalette);
      expect(accent.palette.id, 'rose_gold');
      expect(accent.toneSelectable, isTrue);
    });
  });

  group('Obsidian เป็นธีมหลัก — ย้ายคนที่ค้างธีมทองครั้งเดียว', () {
    test('ไม่เคยเลือกธีม → Obsidian', () async {
      SharedPreferences.setMockInitialValues({});
      final p = ThemeProvider();
      await p.init();
      expect(p.id, 'obsidian');
    });

    test('ค้างธีมทองเดิม → ย้ายเป็น Obsidian และบันทึกลงเครื่อง', () async {
      SharedPreferences.setMockInitialValues({'tpix_trade_palette_id': 'classic'});
      final p = ThemeProvider();
      await p.init();
      expect(p.id, 'obsidian');
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('tpix_trade_palette_id'), 'obsidian');
    });

    test('ย้ายแล้วผู้ใช้กดกลับธีมทองเอง → เปิดแอปครั้งต่อไปต้องไม่ถูกย้ายซ้ำ', () async {
      SharedPreferences.setMockInitialValues({'tpix_trade_palette_id': 'classic'});
      final first = ThemeProvider();
      await first.init();
      await first.setPalette('classic');

      final again = ThemeProvider();
      await again.init();
      expect(again.id, 'classic');
    });

    test('XP Silver ไม่ถูกย้าย — คนเลือกธีมสว่างเพราะต้องการจริง', () async {
      SharedPreferences.setMockInitialValues({'tpix_trade_palette_id': 'xp_silver'});
      final p = ThemeProvider();
      await p.init();
      expect(p.id, 'xp_silver');
    });
  });
}
