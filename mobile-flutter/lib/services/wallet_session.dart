/// TPIX TRADE — เซสชันกระเป๋าแบบยาว (โทเคนจากเซิร์ฟเวอร์หลังเซ็นผ่าน)
///
/// เจ้าของ: "เชื่อมแล้ว เวลาเปิดแอพมาใหม่ก็ต้องมาเชื่อมใหม่อีก ทั้งที่ควรเชื่อมค้างไว้ได้เลย"
/// เซิร์ฟเวอร์เคยรู้จักเราแค่ 4 ชั่วโมงและผูกกับ IP — มือถือเปลี่ยน IP ตลอด
/// ตอนนี้หลังเซ็นผ่านครั้งเดียว เซิร์ฟเวอร์ออกโทเคน 30 วันให้ เก็บใน secure storage
/// แล้วแนบหัว `X-Wallet-Session` ทุกคำขอ — ไม่ต้องไปเซ็นที่ TPIX Wallet ซ้ำอีก
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class WalletSession {
  WalletSession._();

  static const headerName = 'X-Wallet-Session';

  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );
  static const _keyToken = 'tpix_trade_wallet_session';
  static const _keyAddress = 'tpix_trade_wallet_session_address';

  static String? _token;
  static String? _address;

  /// โทเคนปัจจุบัน (null = ยังไม่เคยเซ็น หรือถูกล้างไปแล้ว)
  static String? get token => _token;

  /// กระเป๋าที่โทเคนนี้เป็นของ — กันเอาโทเคนของกระเป๋าเก่าไปใช้กับกระเป๋าใหม่
  static String? get address => _address;

  static bool get hasToken => _token != null && _token!.isNotEmpty;

  /// โหลดจากดิสก์ตอนเปิดแอป — เรียกก่อนคำขอแรกที่ต้องยืนยัน
  static Future<void> load() async {
    try {
      _token = await _storage.read(key: _keyToken);
      _address = await _storage.read(key: _keyAddress);
      if (_token != null && !RegExp(r'^[0-9a-f]{64}$').hasMatch(_token!)) {
        await clear();
      }
    } catch (e) {
      debugPrint('WalletSession.load: ${e.runtimeType}');
      _token = null;
      _address = null;
    }
  }

  static Future<void> save(String token, String address) async {
    _token = token;
    _address = address.toLowerCase();
    try {
      await _storage.write(key: _keyToken, value: token);
      await _storage.write(key: _keyAddress, value: _address);
    } catch (e) {
      debugPrint('WalletSession.save: ${e.runtimeType}');
    }
  }

  static Future<void> clear() async {
    _token = null;
    _address = null;
    try {
      await _storage.delete(key: _keyToken);
      await _storage.delete(key: _keyAddress);
    } catch (_) {}
  }

  /// โทเคนใช้กับกระเป๋านี้ได้ไหม (มีโทเคน และเป็นของกระเป๋าเดียวกัน)
  static bool isFor(String? address) =>
      hasToken && address != null && _address == address.toLowerCase();

  /// หัวที่ต้องแนบไปกับคำขอ — ว่างเมื่อไม่มีโทเคน
  static Map<String, String> headers() =>
      hasToken ? {headerName: _token!} : const {};
}
