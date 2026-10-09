/// TPIX TRADE — สถานะ KYC ล่าสุดของแอป (ตัวเดียวทั้งแอป)
///
/// มีไว้เพราะผลยืนยันตัวตนมาได้สองทาง และทั้งสองทางต้องเห็นของชุดเดียวกัน:
///   1. หน้า `/kyc` โหลดเอง (เปิดหน้า · ดึงลง · ถามผลซ้ำทุก 20 วินาที)
///   2. deep link `tpixtrade://kyc?result=ok&completion=…` ที่ Thaiprompt ส่งกลับมา
///      — มาถึงตอนไหนก็ได้ รวมถึงตอนที่หน้า `/kyc` เปิดค้างอยู่แล้ว
/// ถ้าต่างคนต่างถือข้อมูล หน้าที่เปิดค้างจะยังบอก "ยังไม่ได้เชื่อม" ทั้งที่เพิ่งเชื่อมสำเร็จ
///
/// ไม่ได้ลงทะเบียนใน MultiProvider โดยตั้งใจ — DeepLinkService ไม่มี context ของ
/// หน้าจอ ใช้ [KycStore.I] ตรงๆ ส่วนหน้าจอรับผ่าน constructor (เทสต์ส่งตัวใหม่ได้)
///
/// ⚠️ รหัสรับผล (completion) ที่ยังแลกไม่สำเร็จเก็บในหน่วยความจำเท่านั้น
///    ห้ามลงดิสก์ ห้าม log ห้ามใส่ใน breadcrumb/รายงานบั๊ก
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart';

import '../models/kyc_models.dart';
import '../services/api_result.dart';
import '../services/kyc_api.dart';

/// ผลของการแลกรหัสรับผลจาก Thaiprompt
enum KycCompleteOutcome {
  /// ผ่านทันที (บัญชี Thaiprompt นี้ยืนยันตัวตนไว้แล้ว)
  approved,

  /// เชื่อมแล้ว แต่ยังต้องไปทำ eKYC ในแอป Thaiprompt ให้เสร็จ
  linked,

  /// บัญชี Thaiprompt นี้ใช้ยืนยันกับบัญชี TPIX TRADE อื่นไปแล้ว
  taken,

  /// รหัสหมดอายุ/ใช้ไปแล้ว/ผิดรูป — ต้องกดยืนยันด้วย Thaiprompt ใหม่
  expired,

  /// ต้องเซ็นยืนยันกระเป๋าก่อน — เก็บรหัสไว้แลกต่อให้เองหลังเซ็นผ่าน
  needsWalletSign,

  /// ยังไม่ได้เชื่อมกระเป๋า — เก็บรหัสไว้แลกต่อให้เองหลังเชื่อม
  noWallet,

  /// โดนจำกัดจำนวนครั้ง — เก็บรหัสไว้ ลองใหม่ได้ภายหลัง
  throttled,

  /// เน็ตหลุด/เซิร์ฟเวอร์สะดุด — เก็บรหัสไว้ ลองใหม่ตอนโหลดสถานะสำเร็จครั้งถัดไป
  failed,

  /// รหัสนี้เพิ่งแลกไป (deep link มาซ้ำ) — ไม่ต้องทำอะไรและไม่ต้องแจ้งซ้ำ
  duplicate,
}

extension KycCompleteOutcomeText on KycCompleteOutcome {
  /// คีย์ข้อความใน locale_provider — null = ไม่ต้องแจ้งอะไร
  String? get messageKey => switch (this) {
        KycCompleteOutcome.approved => 'kyc.link.approved',
        KycCompleteOutcome.linked => 'kyc.link.linked',
        KycCompleteOutcome.taken => 'kyc.err.THAIPROMPT_TAKEN',
        KycCompleteOutcome.expired => 'kyc.link.expired',
        KycCompleteOutcome.needsWalletSign => 'kyc.link.needsSign',
        KycCompleteOutcome.noWallet => 'kyc.link.noWallet',
        KycCompleteOutcome.throttled => 'kyc.err.RATE_LIMITED',
        KycCompleteOutcome.failed => 'kyc.link.failed',
        KycCompleteOutcome.duplicate => null,
      };

  bool get isSuccess =>
      this == KycCompleteOutcome.approved || this == KycCompleteOutcome.linked;
}

class KycStore extends ChangeNotifier {
  /// สร้างใหม่ได้เฉพาะเทสต์ — แอปจริงใช้ [I] ตัวเดียว
  @visibleForTesting
  KycStore();

  static final KycStore I = KycStore._();

  KycStore._();

  /// รหัสรับผลมีอายุ 8 นาทีฝั่งเซิร์ฟเวอร์ — เก็บนานกว่านั้นก็แลกไม่ผ่านอยู่ดี
  static const Duration pendingTtl = Duration(minutes: 8);

  String? _wallet;
  KycStatus? _status;
  DateTime? _updatedAt;
  int _revision = 0;

  bool _completing = false;
  String? _inFlightCompletion;
  String? _lastUsedCompletion;
  ApiErr? _lastCompleteError;

  String? _pendingCompletion;
  String? _pendingWallet;
  DateTime? _pendingAt;

  /// เลขรุ่นของข้อมูล — เพิ่มทุกครั้งที่มี payload ใหม่
  ///
  /// หน้าจอจดเลขนี้ไว้ก่อนยิงคำขอ ถ้าตอนคำตอบกลับมาเลขเปลี่ยนไปแล้ว แปลว่ามีของ
  /// ใหม่กว่ามาถึงระหว่างรอ (เช่น deep link แลกรหัสสำเร็จ) → ทิ้งคำตอบเก่าทิ้ง
  /// ไม่งั้นสถานะ "ยังไม่เชื่อม" ที่ยิงไปก่อนจะมาทับ "เชื่อมแล้ว" ที่เพิ่งได้
  int get revision => _revision;

  /// กำลังแลกรหัสรับผลอยู่ (หน้าจอโชว์แถบ "กำลังเชื่อมบัญชี Thaiprompt…")
  bool get isCompleting => _completing;

  DateTime? get updatedAt => _updatedAt;

  /// เหตุผลของการแลกรหัสครั้งล่าสุดที่ล้มเหลว (ไว้ดู retryAfterSeconds ฯลฯ)
  ApiErr? get lastCompleteError => _lastCompleteError;

  /// สถานะของกระเป๋านี้ — null เมื่อยังไม่มี หรือเป็นของกระเป๋าใบอื่น
  /// (สลับกระเป๋าแล้วต้องไม่เห็นผล KYC ของบัญชีเก่าค้างอยู่บนจอ)
  KycStatus? statusFor(String? wallet) {
    if (wallet == null || _wallet == null) return null;
    return _wallet == wallet.trim().toLowerCase() ? _status : null;
  }

  void update(String wallet, KycStatus status) {
    _wallet = wallet.trim().toLowerCase();
    _status = status;
    _updatedAt = DateTime.now();
    _revision++;
    notifyListeners();
  }

  /// ล้างทุกอย่าง (ตัดการเชื่อมต่อกระเป๋า / เทสต์)
  void clear() {
    _wallet = null;
    _status = null;
    _updatedAt = null;
    _revision++;
    _clearPending();
    _lastCompleteError = null;
    notifyListeners();
  }

  // ── รหัสรับผลที่ค้างแลก ─────────────────────────────────────────────

  /// มีรหัสรับผลค้างให้กระเป๋านี้แลกต่อไหม
  bool hasPendingFor(String? wallet) => _pendingFor(wallet) != null;

  String? _pendingFor(String? wallet) {
    final code = _pendingCompletion;
    final at = _pendingAt;
    if (code == null || at == null) return null;
    if (DateTime.now().difference(at) > pendingTtl) {
      _clearPending();
      return null;
    }
    if (wallet == null) return null;
    // ค้างไว้ตอนยังไม่รู้กระเป๋า (ยังไม่ได้เชื่อม) = ให้กระเป๋าแรกที่พร้อมลองแลก
    // ถ้าเป็นคนละบัญชีกับที่เริ่ม เซิร์ฟเวอร์ปฏิเสธเอง (ผูกกับบัญชีที่กดเริ่ม)
    final owner = _pendingWallet;
    if (owner != null && owner != wallet.trim().toLowerCase()) return null;
    return code;
  }

  void _hold(String completion, String? wallet) {
    _pendingCompletion = completion;
    _pendingWallet = wallet?.trim().toLowerCase();
    _pendingAt = DateTime.now();
  }

  void _clearPending() {
    _pendingCompletion = null;
    _pendingWallet = null;
    _pendingAt = null;
  }

  /// แลกรหัสที่ค้างไว้ต่อ (เรียกหลังผู้ใช้เชื่อม/เซ็นกระเป๋าผ่านแล้ว)
  /// null = ไม่มีอะไรค้าง ไม่ได้ยิงอะไร
  Future<KycCompleteOutcome?> completePending({
    required KycApi api,
    required String wallet,
  }) async {
    final code = _pendingFor(wallet);
    if (code == null) return null;
    return complete(api: api, wallet: wallet, completion: code);
  }

  // ── แลกรหัสรับผล ───────────────────────────────────────────────────

  /// แลกรหัสรับผลที่ได้จาก deep link แล้วเก็บสถานะใหม่
  ///
  /// ไม่ throw — ทุกทางจบด้วย [KycCompleteOutcome] ที่ผู้เรียกเอาไปแปลเป็นข้อความ
  Future<KycCompleteOutcome> complete({
    required KycApi api,
    required String? wallet,
    required String completion,
  }) async {
    // deep link มาซ้ำ (router-fallback + app_links, หรือผู้ใช้กดลิงก์เดิมสองที)
    // แลกซ้ำได้ 422 "หมดอายุ" กลับมา แล้วผู้ใช้จะเห็นข้อความผิดทั้งที่สำเร็จไปแล้ว
    if (completion == _lastUsedCompletion || completion == _inFlightCompletion) {
      return KycCompleteOutcome.duplicate;
    }
    if (wallet == null || wallet.trim().isEmpty) {
      _hold(completion, null);
      return KycCompleteOutcome.noWallet;
    }

    _completing = true;
    _inFlightCompletion = completion;
    notifyListeners();

    ApiResult<KycStatus> res;
    try {
      res = await api.completeThaiprompt(wallet: wallet, completion: completion);
    } catch (_) {
      // KycApi ไม่ throw อยู่แล้ว — กันไว้ไม่ให้ _completing ค้าง true ตลอดกาล
      res = const ApiErr<KycStatus>(KycErrorCodes.unknown, 'ทำรายการไม่สำเร็จ');
    }
    _completing = false;
    _inFlightCompletion = null;

    switch (res) {
      case ApiOk<KycStatus>(:final data):
        _lastUsedCompletion = completion;
        _lastCompleteError = null;
        _clearPending();
        update(wallet, data); // แจ้งผู้ฟังในตัว
        return data.isApproved
            ? KycCompleteOutcome.approved
            : KycCompleteOutcome.linked;
      case ApiErr<KycStatus>():
        final err = res;
        _lastCompleteError = err;
        final outcome = _outcomeFor(err);
        switch (outcome) {
          case KycCompleteOutcome.needsWalletSign:
          case KycCompleteOutcome.throttled:
          case KycCompleteOutcome.failed:
            // รหัสยังน่าจะใช้ได้ — เก็บไว้แลกต่อเมื่อพร้อม
            _hold(completion, wallet);
          case KycCompleteOutcome.taken:
          case KycCompleteOutcome.expired:
            // เซิร์ฟเวอร์ตัดสินแล้ว (และกินรหัสไปแล้ว) — แลกซ้ำก็ไม่ผ่าน
            _lastUsedCompletion = completion;
            _clearPending();
          default:
            break;
        }
        notifyListeners();
        return outcome;
    }
  }

  static KycCompleteOutcome _outcomeFor(ApiErr err) {
    if (err.needsWalletSign) return KycCompleteOutcome.needsWalletSign;
    if (err.isThrottled) return KycCompleteOutcome.throttled;
    switch (err.code) {
      case KycErrorCodes.thaipromptTaken:
        return KycCompleteOutcome.taken;
      case KycErrorCodes.thaipromptLinkFailed:
      case KycErrorCodes.invalidCompletion:
      case KycErrorCodes.validation:
        return KycCompleteOutcome.expired;
      case KycErrorCodes.noWallet:
      case KycErrorCodes.invalidWallet:
        return KycCompleteOutcome.noWallet;
    }
    return KycCompleteOutcome.failed;
  }
}

/// Developed by Xman Studio
