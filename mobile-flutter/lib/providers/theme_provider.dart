/// TPIX TRADE — Theme Provider
/// เก็บ + จำชุดสีที่ผู้ใช้เลือก แล้วสลับทั้งแอป
/// — เลียนแพตเทิร์นจาก AccentProvider / LocaleProvider
///
/// ต่างจาก AccentProvider ตรงที่ตัวนั้นสลับแค่โทนโลหะ (ทอง/แพลตินัม/โรสโกลด์)
/// บนฐานมืดตายตัว ส่วนตัวนี้สลับ "ทั้งชุดสี" รวมพื้นหลังกับตัวอักษร จึงทำธีม
/// สว่างอย่าง XP Silver ได้
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/theme/app_colors.dart';

class ThemeProvider extends ChangeNotifier {
  static const String _key = 'tpix_trade_palette_id';

  // คนที่ไม่เคยกดเลือกธีม = ไม่มีค่าใน prefs (บันทึกเฉพาะตอนกดเลือก) → ได้ชุดปริยาย
  // ใหม่ (Obsidian) เอง ส่วนคนที่เคยกดเลือกไว้ยังได้ชุดที่ตัวเองเลือก
  TradePalette _palette = kDefaultPalette;

  TradePalette get palette => _palette;
  String get id => _palette.id;

  /// ชุดสีทั้งหมดให้หน้าตั้งค่าวนแสดง
  List<TradePalette> get available => kTradePalettes;

  /// เปลี่ยนค่านี้ทุกครั้งที่สลับชุดสี แล้วเอาไปใส่เป็น key ของ MaterialApp
  ///
  /// จำเป็นเพราะสีถูกอ่านผ่าน static getter ไม่ได้ผูกกับ InheritedWidget
  /// Flutter จึงไม่รู้ว่าต้อง rebuild อะไร — เปลี่ยน key คือการบอกว่า
  /// "ต้นไม้เดิมใช้ไม่ได้แล้ว สร้างใหม่ทั้งหมด" ซึ่งได้ผลแน่นอนที่สุด
  Key get appKey => ValueKey('palette-${_palette.id}');

  /// ธงย้ายครั้งเดียว — Obsidian เป็นธีมหลักของแอปแล้ว (ตรงกับ TPIX Wallet)
  /// คนที่ค้างอยู่บนธีมทองเดิมถูกย้ายมา Obsidian หนึ่งครั้ง หลังจากนั้นถ้าเขา
  /// กดกลับไปธีมทองเอง เราเคารพตัวเลือกนั้น (ธงตั้งแล้ว ไม่ย้ายซ้ำ)
  /// XP Silver ไม่ย้าย — เป็นธีมสว่างที่คนเลือกเพราะอยากได้พื้นสว่างจริง ๆ
  static const String _migratedKey = 'tpix_trade_obsidian_migrated_v1';

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    var stored = prefs.getString(_key);
    if (prefs.getBool(_migratedKey) != true) {
      if (stored == kClassicPalette.id) {
        stored = kObsidianPalette.id;
        await prefs.setString(_key, stored);
      }
      await prefs.setBool(_migratedKey, true);
    }
    _palette = paletteFromId(stored);
    AppColors.apply(_palette);
    notifyListeners();
  }

  Future<void> setPalette(String id) async {
    final next = paletteFromId(id);
    if (next.id == _palette.id) return;

    _palette = next;
    AppColors.apply(next);

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, next.id);

    notifyListeners();
  }
}
