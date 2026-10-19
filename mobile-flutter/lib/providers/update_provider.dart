/// TPIX TRADE — Update Provider
/// ตรวจสอบเวอร์ชันใหม่แบบ background + จำเวลาครั้งล่าสุด
///
/// เช็กเมื่อไหร่:
///   - เปิดแอปใหม่ (cold start) → เช็กทุกครั้ง (คำขอเดียว ถูกมาก)
///   - กลับเข้าแอปจากพื้นหลัง → เช็กถ้าห่างจากครั้งก่อนเกิน [_resumeGap]
///
/// 🔴 เดิมเช็กแค่ตอน cold start และเว้น 6 ชม. — แอนดรอยด์เก็บแอปค้างในพื้นหลัง
///    เป็นวัน ๆ ผู้ใช้ส่วนใหญ่จึงไม่เคยเห็นแถบอัปเดตเลยหลังปล่อยรุ่นใหม่
///
/// Developed by Xman Studio

import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/update_service.dart';

class UpdateProvider extends ChangeNotifier with WidgetsBindingObserver {
  final _service = UpdateService();

  /// กลับเข้าแอปแล้วเช็กใหม่ได้เมื่อห่างจากครั้งก่อนเกินนี้
  static const _resumeGap = Duration(minutes: 30);

  UpdateProvider() {
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) checkInBackground();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  UpdateResult? _result;
  bool _isChecking = false;
  DateTime? _lastChecked;
  bool _dismissed = false;

  UpdateResult? get result => _result;
  bool get isChecking => _isChecking;
  bool get hasUpdate => _result?.available == true && !_dismissed;

  /// เช็กสำเร็จครั้งล่าสุดเมื่อไหร่ (null = ยังไม่เคยในรอบนี้)
  DateTime? get lastChecked => _lastChecked;

  UpdateService get service => _service;

  static const _keyLastChecked = 'tpix_trade_update_last_checked';
  static const _keyDismissedVersion = 'tpix_trade_update_dismissed';

  /// ตรวจสอบใน background — silent, ไม่ block UI
  /// เว้น [_resumeGap] ระหว่าง check แต่ละครั้ง ([force] = ข้ามการเว้น)
  Future<void> checkInBackground({bool force = false}) async {
    if (_isChecking) return;

    final prefs = await SharedPreferences.getInstance();
    final lastMs = prefs.getInt(_keyLastChecked);
    final now = DateTime.now();

    if (!force && lastMs != null) {
      final last = DateTime.fromMillisecondsSinceEpoch(lastMs);
      // นาฬิกาเครื่องถูกตั้งย้อนหลัง (last อยู่ในอนาคต) → ถือว่าถึงเวลาแล้ว ไม่งั้นเงียบไปจนกว่าจะถึงเวลานั้น
      final elapsed = now.difference(last);
      if (!elapsed.isNegative && elapsed < _resumeGap) {
        _lastChecked = last;
        return; // ยังไม่ถึงเวลา check ใหม่
      }
    }

    _isChecking = true;
    notifyListeners();

    try {
      final result = await _service.checkForUpdate();
      _result = result;
      _lastChecked = now;
      await prefs.setInt(_keyLastChecked, now.millisecondsSinceEpoch);

      // เช็คว่าถูก dismiss แล้วหรือยัง (dismiss จะเก็บเลข version)
      if (result.available) {
        final dismissedVer = prefs.getString(_keyDismissedVersion);
        _dismissed = dismissedVer == result.latestVersion;
      } else {
        _dismissed = false;
      }
    } catch (e) {
      debugPrint('UpdateProvider check: ${e.runtimeType}');
    }

    _isChecking = false;
    notifyListeners();
  }

  /// ผู้ใช้กด "ไว้ทีหลัง" — จำ version นี้ไว้ ไม่แสดงอีกจนกว่าจะมี version ใหม่กว่า
  Future<void> dismiss() async {
    if (_result?.latestVersion == null) return;
    _dismissed = true;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyDismissedVersion, _result!.latestVersion!);
    notifyListeners();
  }

  /// Manual check (จากปุ่มใน Settings) — force, ไม่เช็คเวลา
  Future<UpdateResult?> manualCheck() async {
    await checkInBackground(force: true);
    return _result;
  }
}
