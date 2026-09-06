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

  TradePalette _palette = kClassicPalette;

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

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _palette = paletteFromId(prefs.getString(_key));
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
