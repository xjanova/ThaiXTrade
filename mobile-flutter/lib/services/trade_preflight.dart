/// TPIX TRADE — ด่านก่อนเงินออกจากกระเป๋า (ยืนยันกระเป๋า + ด่านยืนยันตัวตนของการเทรด)
///
/// สวอปบน BSC / TPIX DEX ออกจากกระเป๋าตรง ด่านฝั่งเซิร์ฟเวอร์ (`VerifyWalletOwnership` +
/// `kyc:trading` หน้า `POST /swap/execute`) จึงไปโผล่ตอน "บันทึกไม้" ซึ่งเงินออกไปแล้ว
/// — ไม้จริงค้างบันทึกไม่ได้ ค่าธรรมเนียมไม่ถูกตรวจ ต้องเช็กให้ผ่าน **ก่อน** approve/swap
/// (เว็บทำแบบเดียวกัน: Trade.vue `kycBlocked` → พาไป /kyc ก่อนส่ง)
///
/// สถานะด่านอ่านจาก `GET /kyc/status` → `gate.features.trading {required, passed}`
/// อ่านไม่ได้และไม่มีค่าที่เพิ่งอ่านไว้ = **ไม่ส่ง** (fail-closed) พร้อมบอกเหตุ
///
/// Developed by Xman Studio
library;

import '../models/kyc_models.dart';
import '../providers/kyc_store.dart';
import 'api_result.dart';
import 'bsc_swap_service.dart' show SwapException;
import 'kyc_api.dart';

/// เหตุที่ส่งไม้ไม่ได้
enum TradeBlockReason {
  /// ยืนยัน (เซ็น) กระเป๋ากับเซิร์ฟเวอร์ไม่ผ่าน / ผู้ใช้ไม่เซ็น
  walletNotVerified,

  /// แอดมินเปิดด่านยืนยันตัวตนของการเทรด และบัญชีนี้ยังไม่ผ่าน — พาไปหน้า KYC
  kycRequired,

  /// อ่านสถานะด่านยืนยันตัวตนไม่ได้ (เน็ตหลุด/เซิร์ฟเวอร์ผิดพลาด) — ไม่รู้ = ไม่ส่ง
  kycUnknown,
}

/// ส่งไม้ไม่ได้เพราะด่านก่อนเทรด — ข้อความสองภาษาพร้อมแสดง + เหตุให้หน้าจอเลือกทางออก
class TradeBlockedException extends SwapException {
  final TradeBlockReason reason;

  const TradeBlockedException(this.reason, String en, String th)
      : super(en, th);

  bool get needsKyc => reason == TradeBlockReason.kycRequired;

  static const walletNotVerified = TradeBlockedException(
    TradeBlockReason.walletNotVerified,
    'Wallet verification failed — nothing was sent. Verify your wallet and try again.',
    'ยืนยันกระเป๋าไม่สำเร็จ — ยังไม่ได้ส่งอะไรออกไป ยืนยันกระเป๋าแล้วลองอีกครั้ง',
  );

  static const kycRequired = TradeBlockedException(
    TradeBlockReason.kycRequired,
    'Verify your identity before trading — nothing was sent.',
    'ต้องยืนยันตัวตนก่อนจึงจะเทรดได้ — ยังไม่ได้ส่งอะไรออกไป',
  );

  static const kycUnknown = TradeBlockedException(
    TradeBlockReason.kycUnknown,
    "Couldn't check your identity-verification status — nothing was sent. Please try again.",
    'ตรวจสถานะการยืนยันตัวตนไม่ได้ — ยังไม่ได้ส่งอะไรออกไป ลองใหม่อีกครั้ง',
  );
}

class TradePreflight {
  TradePreflight._();

  /// คีย์ฟีเจอร์ของด่านเทรด/สวอป — ต้องตรงกับ `config/kyc.php` และ middleware `kyc:trading`
  static const String tradingFeature = 'trading';

  /// ค่าที่เพิ่งอ่านมาไม่เกินเท่านี้ = ใช้ได้เลย ไม่ต้องถามเซิร์ฟเวอร์ซ้ำทุกไม้
  static const Duration freshFor = Duration(seconds: 60);

  /// ถามเซิร์ฟเวอร์ไม่ได้ ยอมใช้ค่าที่อ่านไว้ไม่เกินเท่านี้ — เก่ากว่านี้ = ไม่รู้ = ไม่ส่ง
  static const Duration staleLimit = Duration(minutes: 10);

  /// ด่านยืนยันตัวตนบล็อกการเทรดของบัญชีนี้ไหม — ตัดสินจาก payload เพียว ๆ (ทดสอบได้)
  ///
  /// กติกาเดียวกับเว็บ: `features.trading.required && !features.trading.passed`
  /// เซิร์ฟเวอร์ไม่ได้ส่งด่านนี้มา (รุ่นเก่า) = ไม่มีด่าน
  static bool kycBlocksTrading(KycGateStatus gate) {
    for (final f in gate.features) {
      if (f.key == tradingFeature) return f.required && !f.passed;
    }
    return false;
  }

  /// ตรวจให้ผ่านก่อน approve/swap — ไม่ผ่านโยน [TradeBlockedException] (ยังไม่ได้ส่งอะไร)
  ///
  /// [isVerified] / [verify] มาจาก WalletProvider (`isVerified` / `verifyWithBackend`)
  /// — กระเป๋าที่ลิงก์จาก TPIX Wallet จะถูกพาไปเซ็นในแอปกระเป๋าแล้วกลับมาเอง
  /// [fetchStatus] / [store] / [now] เปลี่ยนได้ในเทสต์
  static Future<void> ensureCanTrade({
    required String address,
    required bool Function() isVerified,
    required Future<bool> Function() verify,
    Future<ApiResult<KycStatus>> Function(String wallet)? fetchStatus,
    KycStore? store,
    DateTime Function()? now,
  }) async {
    final kyc = store ?? KycStore.I;
    final fetch = fetchStatus ?? KycApi().fetchStatus;
    final clock = now ?? DateTime.now;

    // 1) กระเป๋าต้องยืนยันกับเซิร์ฟเวอร์แล้ว — ไม่งั้น /swap/execute ตอบ 403 หลังเงินออก
    if (!isVerified()) {
      if (!await verify()) throw TradeBlockedException.walletNotVerified;
    }

    // 2) ด่านยืนยันตัวตนของการเทรด
    Duration? cachedAge() {
      final at = kyc.updatedAt;
      if (at == null || kyc.statusFor(address) == null) return null;
      return clock().difference(at);
    }

    final age = cachedAge();
    if (age != null && age <= freshFor) {
      _decide(kyc.statusFor(address)!.gate);
      return;
    }

    var result = await fetch(address);
    // เซสชันฝั่งเซิร์ฟเวอร์หมดอายุทั้งที่แอปยังคิดว่ายืนยันอยู่ — เซ็นใหม่หนึ่งครั้งแล้วถามซ้ำ
    if (result case ApiErr<KycStatus> err when err.needsWalletSign) {
      if (!await verify()) throw TradeBlockedException.walletNotVerified;
      result = await fetch(address);
    }

    switch (result) {
      case ApiOk<KycStatus>(:final data):
        kyc.update(address, data);
        _decide(data.gate);
      case ApiErr<KycStatus> err:
        if (err.needsWalletSign) throw TradeBlockedException.walletNotVerified;
        // ถามไม่ได้รอบนี้ — ใช้ค่าที่เพิ่งอ่านไว้ได้ ถ้ายังไม่เก่าเกิน
        final staleAge = cachedAge();
        if (staleAge != null && staleAge <= staleLimit) {
          _decide(kyc.statusFor(address)!.gate);
          return;
        }
        throw TradeBlockedException.kycUnknown;
    }
  }

  static void _decide(KycGateStatus gate) {
    if (kycBlocksTrading(gate)) throw TradeBlockedException.kycRequired;
  }
}
