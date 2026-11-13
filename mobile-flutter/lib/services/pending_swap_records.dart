/// TPIX TRADE — คิวบันทึกไม้ที่ยังแจ้งเซิร์ฟเวอร์ไม่สำเร็จ (mobile)
///
/// สวอปลงเชนไปแล้ว แต่ `POST /swap/execute` ล้ม (ลายเซ็นกระเป๋าหมดอายุ → 403, ติดด่าน KYC,
/// เน็ตหลุด, เซิร์ฟเวอร์ล่ม, แอปถูกปิดระหว่างรอลงบล็อก) — เดิมแจ้งครั้งเดียวแบบ best-effort
/// แล้วจบ ไม้จริงหายจากประวัติถาวรทั้งที่ผู้ใช้เห็น "สำเร็จ" และค่าธรรมเนียมไม่เคยถูกตรวจ
///
/// mirror มาจากคิวของเว็บ (useSwap.js `tpix.pendingSwapRecords`) กติกาเดียวกัน:
///   - เข้าคิว **ทันทีที่รู้ tx hash** (ก่อนรอลงบล็อก) หนึ่ง tx = หนึ่งแถวเสมอ
///   - 200/201 (เซิร์ฟเวอร์มีแถวแล้ว) · 409 · 422 = เอาออก
///   - เน็ตหลุด · 403 · 5xx · 202 `recorded:false` = เก็บไว้ ส่งใหม่หลังสวอป / ตอนเปิดแอป / ตอนโหลดพอร์ต
///   - 202 ที่เชนไม่เห็นธุรกรรมเลยเกิน 1 วัน = หลุดไปแล้ว ทิ้ง
///   - แถวที่กำลังทำขั้นค่าธรรมเนียมอยู่ ([markInflight]) ตัว flush ต้องข้าม
///
/// ที่เก็บ: SharedPreferences (แบบเดียวกับ localStorage ของเว็บ) — ในแถวมีแค่ที่อยู่กระเป๋า,
/// tx hash และจำนวน ซึ่งเป็นข้อมูลสาธารณะบนเชนอยู่แล้ว ไม่มีความลับ
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';

/// ตัวส่งบันทึกหนึ่งรายการ — ของจริงคือ [ApiService.postSwapRecord]
typedef SwapRecordPoster = Future<SwapRecordOutcome> Function(
    Map<String, dynamic> payload);

class PendingSwapRecords {
  PendingSwapRecords._()
      : _post = ((payload) => ApiService().postSwapRecord(payload)),
        _now = DateTime.now;

  /// ตัวเดียวทั้งแอป — ชุด in-flight ต้องเป็นของจริงตัวเดียว
  static final PendingSwapRecords I = PendingSwapRecords._();

  /// สำหรับเทสต์ — ส่งตัวส่งปลอม/นาฬิกาปลอมเข้ามาแทนการยิงเน็ตจริง
  @visibleForTesting
  PendingSwapRecords.forTest({
    required SwapRecordPoster poster,
    DateTime Function()? now,
  })  : _post = poster,
        _now = now ?? DateTime.now;

  static const String storageKey = 'tpix_trade_pending_swap_records';

  /// เก็บได้มากสุดกี่แถว (เกิน = ทิ้งแถวเก่าสุด) — เท่ากับเว็บ
  static const int maxRecords = 20;

  /// 202 `recorded:false` นานเกินนี้ = เชนไม่เคยเห็นธุรกรรม (หลุดไปแล้ว) — เท่ากับเว็บ
  static const Duration notOnChainMaxAge = Duration(hours: 24);

  /// แถวที่ยังส่งไม่ผ่านเพราะ 403/เน็ต/5xx เก็บได้นานสุดเท่านี้
  /// (นานกว่าเว็บโดยตั้งใจ — ไม้ลงเชนจริงแล้ว ผู้ใช้อาจใช้เวลาหลายวันกว่าจะยืนยันตัวตน/กระเป๋า)
  static const Duration maxAge = Duration(days: 30);

  static const String _queuedAtKey = '_queuedAt';

  final SwapRecordPoster _post;
  final DateTime Function() _now;

  /// tx ที่กำลังทำขั้นค่าธรรมเนียมอยู่ (ในหน่วยความจำเท่านั้น — เปิดแอปใหม่ = ชุดว่าง)
  final Set<String> _inflight = <String>{};

  /// งานอ่าน-แก้-เขียนต่อคิวกัน — กันสองงานอ่านรายการเดียวกันแล้วเขียนทับกัน
  Future<void> _lock = Future<void>.value();

  Future<int>? _flushing;
  String? _flushingFor;

  static String _lower(Object? v) => (v ?? '').toString().trim().toLowerCase();

  // ── สร้างแถว ──

  /// เนื้อคำขอของ `POST /swap/execute` — คีย์/รูปแบบต้องตรงกับที่เว็บส่ง (useSwap.js)
  ///
  /// [pair] / [side] / [price] ส่งเมื่อผู้เรียกรู้ (ไม้จากกระดานเทรด) — ค่าที่ผิดรูปถูกตัดทิ้ง
  /// เพราะเซิร์ฟเวอร์ตอบ 422 ทั้งคำขอ = ไม้หายจากประวัติ เพียงเพราะข้อมูลประกอบผิด
  /// ราคา = ราคาที่ได้จริงจากยอดของไม้ (quote ต่อ 1 base) · คิดไม่ได้ใช้ [price] ที่ส่งมา
  static Map<String, dynamic> buildRecord({
    required String fromToken,
    required String toToken,
    required double fromAmount,
    required double toAmount,
    required double feeAmount,
    required String txHash,
    required int chainId,
    required String walletAddress,
    bool? feeCollected,
    String? feeTxHash,
    String? pair,
    String? side,
    double? price,
  }) {
    final record = <String, dynamic>{
      'from_token': fromToken,
      'to_token': toToken,
      'from_amount': fromAmount,
      'to_amount': toAmount,
      'fee_amount': feeAmount,
      'tx_hash': txHash,
      'chain_id': chainId,
      'wallet_address': walletAddress,
    };
    if (feeCollected != null) record['fee_collected'] = feeCollected;
    if (feeTxHash != null && feeTxHash.isNotEmpty) {
      record['fee_tx_hash'] = feeTxHash;
    }

    final p = pair?.trim().replaceAll('-', '/');
    if (p != null &&
        RegExp(r'^[A-Za-z0-9]{1,15}/[A-Za-z0-9]{1,15}$').hasMatch(p)) {
      record['pair'] = p;
    }
    final s = side?.trim().toLowerCase();
    if (s == 'buy' || s == 'sell') {
      record['side'] = s;
      // buy: จ่าย quote ได้ base → ราคา = จ่าย/ได้ · sell: จ่าย base ได้ quote → ราคา = ได้/จ่าย
      final fill = s == 'buy'
          ? (toAmount > 0 ? fromAmount / toAmount : double.nan)
          : (fromAmount > 0 ? toAmount / fromAmount : double.nan);
      if (fill.isFinite && fill > 0) record['price'] = fill;
    }
    if (!record.containsKey('price') &&
        price != null &&
        price.isFinite &&
        price > 0) {
      record['price'] = price;
    }
    return record;
  }

  // ── กติกาเก็บ/ทิ้ง (ฟังก์ชันเพียว) ──

  /// หลังส่งแล้วได้ [outcome] แถวที่อยู่ในคิวมาแล้ว [age] ต้องเก็บไว้ต่อไหม
  static bool shouldKeep(SwapRecordOutcome outcome, Duration age) {
    switch (outcome) {
      case SwapRecordOutcome.recorded:
      case SwapRecordOutcome.rejected:
        return false;
      case SwapRecordOutcome.notYetOnChain:
        return age <= notOnChainMaxAge;
      case SwapRecordOutcome.retryLater:
      case SwapRecordOutcome.offline:
        return age <= maxAge;
    }
  }

  // ── ที่เก็บ ──

  Future<T> _synchronized<T>(Future<T> Function() fn) {
    final done = Completer<void>();
    final previous = _lock;
    _lock = done.future;
    return previous.then((_) => fn()).whenComplete(done.complete);
  }

  Future<List<Map<String, dynamic>>> _read() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(storageKey);
      if (raw == null || raw.isEmpty) return [];
      final decoded = jsonDecode(raw);
      if (decoded is! List) return [];
      return decoded
          .whereType<Map>()
          .map((m) => m.map((k, v) => MapEntry(k.toString(), v)))
          .where((m) => _lower(m['tx_hash']).isNotEmpty)
          .toList();
    } catch (e) {
      debugPrint('PendingSwapRecords.read: ${e.runtimeType}');
      return [];
    }
  }

  Future<void> _write(List<Map<String, dynamic>> list) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (list.isEmpty) {
        await prefs.remove(storageKey);
        return;
      }
      final kept = list.length > maxRecords
          ? list.sublist(list.length - maxRecords)
          : list;
      await prefs.setString(storageKey, jsonEncode(kept));
    } catch (e) {
      // เก็บไม่ได้ — ยอมเสียประวัติไม้นี้ ไม่กระทบตัวสวอป
      debugPrint('PendingSwapRecords.write: ${e.runtimeType}');
    }
  }

  /// แถวทั้งหมดในคิว (สำเนา — แก้แล้วไม่กระทบที่เก็บ)
  Future<List<Map<String, dynamic>>> all() => _synchronized(_read);

  /// เขียน/แทนที่บันทึกของ tx นี้ (หนึ่ง tx = หนึ่งแถวเสมอ) — จำเวลาที่เข้าคิวครั้งแรกไว้
  Future<void> upsert(Map<String, dynamic> record) => _synchronized(() async {
        final hash = _lower(record['tx_hash']);
        if (hash.isEmpty) return;
        final list = await _read();
        final existing = list
            .where((r) => _lower(r['tx_hash']) == hash)
            .map((r) => r[_queuedAtKey])
            .whereType<num>()
            .firstOrNull;
        final queuedAt = existing?.toInt() ?? _now().millisecondsSinceEpoch;
        await _write([
          ...list.where((r) => _lower(r['tx_hash']) != hash),
          {...record, _queuedAtKey: queuedAt},
        ]);
      });

  Future<void> remove(String txHash) => _synchronized(() async {
        final hash = _lower(txHash);
        final list = await _read();
        final kept = list.where((r) => _lower(r['tx_hash']) != hash).toList();
        if (kept.length != list.length) await _write(kept);
      });

  // ── แถวที่กำลังทำขั้นค่าธรรมเนียม ──

  /// ตัว flush ต้องข้าม tx นี้จนกว่าจะ [clearInflight] — ไม่งั้นบันทึกชั่วคราว
  /// (fee_collected:false) ถูกส่งไปก่อนบันทึกจริงที่มี hash ของค่าธรรมเนียม
  void markInflight(String txHash) => _inflight.add(_lower(txHash));

  void clearInflight(String txHash) => _inflight.remove(_lower(txHash));

  // ── ส่ง ──

  static Map<String, dynamic> _payload(Map<String, dynamic> record) =>
      Map<String, dynamic>.fromEntries(
          record.entries.where((e) => !e.key.startsWith('_')));

  Duration _ageOf(Map<String, dynamic> record) {
    final at = record[_queuedAtKey];
    if (at is! num) return Duration.zero;
    final age = _now().millisecondsSinceEpoch - at.toInt();
    return Duration(milliseconds: age < 0 ? 0 : age);
  }

  Future<SwapRecordOutcome> _send(Map<String, dynamic> record) async {
    SwapRecordOutcome outcome;
    try {
      outcome = await _post(_payload(record));
    } catch (e) {
      debugPrint('PendingSwapRecords.post: ${e.runtimeType}');
      outcome = SwapRecordOutcome.offline;
    }
    if (!shouldKeep(outcome, _ageOf(record))) {
      await remove(_lower(record['tx_hash']));
    }
    return outcome;
  }

  /// ส่งบันทึกของ [txHash] ที่อยู่ในคิวตอนนี้หนึ่งครั้ง — ผ่าน/ปฏิเสธถาวร = เอาออก · อื่น ๆ = เก็บไว้
  /// ไม่มีแถวของ tx นี้ในคิว = [SwapRecordOutcome.retryLater] (ไม่ได้ส่งอะไร)
  Future<SwapRecordOutcome> submit(String txHash) async {
    final hash = _lower(txHash);
    final record =
        (await all()).where((r) => _lower(r['tx_hash']) == hash).firstOrNull;
    if (record == null) return SwapRecordOutcome.retryLater;
    return _send(record);
  }

  /// ลองส่งบันทึกที่ค้างของกระเป๋านี้อีกรอบ — คืนจำนวนที่เซิร์ฟเวอร์รับแล้ว
  ///
  /// เรียกซ้อนกันได้ (หลายหน้าจอขอพร้อมกัน) = ได้รอบเดิม · ยิงไม่ถึงเซิร์ฟเวอร์ = หยุดรอบนี้ทันที
  /// (ไม่ไล่ยิงจนครบทุกแถวแล้วรอ timeout ทีละแถว)
  Future<int> flush(String? walletAddress) {
    final wallet = _lower(walletAddress);
    if (wallet.isEmpty) return Future.value(0);
    final running = _flushing;
    if (running != null && _flushingFor == wallet) return running;
    late final Future<int> run;
    run = _flush(wallet).whenComplete(() {
      if (identical(_flushing, run)) {
        _flushing = null;
        _flushingFor = null;
      }
    });
    _flushing = run;
    _flushingFor = wallet;
    return run;
  }

  Future<int> _flush(String wallet) async {
    var sent = 0;
    for (final record in await all()) {
      final hash = _lower(record['tx_hash']);
      if (_lower(record['wallet_address']) != wallet ||
          _inflight.contains(hash)) {
        continue;
      }
      final outcome = await _send(record);
      if (outcome == SwapRecordOutcome.recorded) sent++;
      if (outcome == SwapRecordOutcome.offline) break;
    }
    return sent;
  }
}
