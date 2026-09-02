/// TPIX TRADE — ไคลเอนต์ของ AI TRADE (`/api/v1/ai-bot/*`)
///
/// กฎเหล็กของไฟล์นี้: **ห้ามกลืนเหตุผล** ทุกคำขอคืน [ApiResult] ที่พก
/// `code` + `message` + `status` ขึ้นไปให้หน้าจอเสมอ — ต่างจาก `_get/_post`
/// ของ `api_service.dart` ที่ `return null` จนหน้าจอบอกทางออกให้ผู้ใช้ไม่ได้
///
/// สิ่งที่ไคลเอนต์นี้ดูแลให้เอง หน้าจอไม่ต้องรู้:
/// - คิวคำขอตัวเดียว (ห้ามยิงขนาน — งบจริง ~15 คำขอ/นาที/IP แชร์กับทุก API ของ
///   แอพ และแชร์กับผู้ใช้คนอื่นที่อยู่หลัง NAT ของค่ายมือถือเดียวกัน)
/// - หยุดยิงเองเมื่อโดน 429 จนครบเวลาใน `Retry-After` (ไม่เผาโควตาซ้ำ)
/// - แยกซองความล้มเหลว 4 แบบที่เซิร์ฟเวอร์ส่งได้จริง ให้เหลือรหัสเดียวกันหมด
///   (ซองปกติ / validator ของ Laravel / throttle / 404-500 ดิบ)
/// - ตรวจรูปแบบที่อยู่กระเป๋าก่อนยิง (ไม่ส่ง = middleware ปล่อยผ่านแล้วไปตาย
///   ที่ controller เป็น INVALID_WALLET เสียคำขอฟรีๆ หนึ่งครั้ง)
///
/// โครงข้อมูลทั้งหมดอยู่ที่ `models/ai_bot_models.dart` และข้อความ 2 ภาษาของ
/// แต่ละรหัสอยู่ที่ `AiBotErrorText.of()` ในไฟล์เดียวกันนั้น
///
/// Developed by Xman Studio

library;

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../core/constants/api_constants.dart';
import '../models/ai_bot_models.dart';
import 'api_result.dart';

/// เส้นทางทั้งหมดของกลุ่ม ai-bot (ไม่เขียน path ดิบตามที่เรียกใช้)
class AiBotEndpoints {
  AiBotEndpoints._();

  // ── สาธารณะ (ไม่ต้องมีกระเป๋า) ──
  static const String catalog = '/ai-bot/catalog';
  static const String marketView = '/ai-bot/market-view';
  static const String risk = '/ai-bot/risk';

  // ── ต้องยืนยันกระเป๋า ──
  static const String status = '/ai-bot/status';
  static const String credits = '/ai-bot/credits';
  static const String welcome = '/ai-bot/welcome';
  static const String topup = '/ai-bot/topup';
  static const String subscribe = '/ai-bot/subscribe';
  static const String cancel = '/ai-bot/cancel';
  static const String bots = '/ai-bot/bots';
  static const String demo = '/ai-bot/demo';
  static const String demoReset = '/ai-bot/demo/reset';
  static const String analytics = '/ai-bot/analytics';
  static const String advice = '/ai-bot/advice';
  static const String decisions = '/ai-bot/decisions';
  static const String trades = '/ai-bot/trades';

  // ── กระเป๋าบอท (กระเป๋าแยกสำหรับโหมดจริง) ──
  static const String wallet = '/ai-bot/wallet';
  static const String walletRefresh = '/ai-bot/wallet/refresh';
  static const String walletWithdraw = '/ai-bot/wallet/withdraw';
  static String walletCancel(int id) => '/ai-bot/wallet/withdraw/$id/cancel';

  static String bot(int id) => '/ai-bot/bots/$id';
  static String botState(int id) => '/ai-bot/bots/$id/state';
  static String botMode(int id) => '/ai-bot/bots/$id/mode';
  static String botTick(int id) => '/ai-bot/bots/$id/tick';
}

/// รหัสข้อผิดพลาดทั้งหมดของ AI TRADE (ทั้งของเซิร์ฟเวอร์และที่แอพตั้งเอง)
///
/// ใช้แทนการพิมพ์สตริงดิบตามที่ต่างๆ — พิมพ์ผิดหนึ่งตัวคือเงื่อนไขที่ไม่มีวัน
/// เป็นจริง แล้วผู้ใช้เจอข้อความ fallback ตลอดกาลโดยไม่มีใครรู้
class AiBotErrorCodes {
  AiBotErrorCodes._();

  // ── กระเป๋า / ตัวตน ──
  static const String invalidWallet = 'INVALID_WALLET';
  static const String walletNotVerified = 'WALLET_NOT_VERIFIED';
  static const String walletIpMismatch = 'WALLET_IP_MISMATCH';
  static const String kycRequired = 'KYC_REQUIRED';

  // ── แพลน / เครดิต ──
  static const String planNotFound = 'PLAN_NOT_FOUND';
  static const String insufficientCredits = 'INSUFFICIENT_CREDITS';
  static const String salesClosed = 'SALES_CLOSED';
  static const String subscribeInProgress = 'SUBSCRIBE_IN_PROGRESS';
  static const String topupUnavailable = 'TOPUP_UNAVAILABLE';
  static const String invalidPack = 'INVALID_PACK';
  static const String noSubscription = 'NO_SUBSCRIPTION';

  // ── บอท ──
  static const String strategyLocked = 'STRATEGY_LOCKED';
  static const String botLimitReached = 'BOT_LIMIT_REACHED';
  static const String pairNoCandles = 'PAIR_NO_CANDLES';
  static const String botNotFound = 'BOT_NOT_FOUND';
  static const String botBanned = 'BOT_BANNED';
  static const String botNotRunning = 'BOT_NOT_RUNNING';
  static const String cloudBot = 'CLOUD_BOT';
  static const String liveDisabled = 'LIVE_DISABLED';
  static const String resetLimit = 'RESET_LIMIT';

  // ── รหัสฝั่งแอพ ──
  /// ยังไม่ได้เชื่อมกระเป๋า — ไม่ยิงเน็ตเลย
  static const String noWallet = 'NO_WALLET';
  static const String network = 'NETWORK';
  static const String timeout = 'TIMEOUT';
  static const String cancelled = 'CANCELLED';
  static const String badPayload = 'BAD_PAYLOAD';
  static const String validation = 'VALIDATION_ERROR';
  static const String rateLimited = 'RATE_LIMITED';
  static const String unknown = 'UNKNOWN';
}

/// ทางออกที่หน้าจอควรเสนอให้ผู้ใช้เมื่อเจอรหัสหนึ่งๆ
///
/// ข้อความ 2 ภาษาอยู่ที่ `AiBotErrorText.of()` ส่วนตัวนี้ตอบคำถามที่ข้อความ
/// ตอบไม่ได้: **จะวางปุ่มอะไรไว้ข้างข้อความนั้น** (ห้ามล้มเงียบ = ต้องมีทางออก)
enum AiBotErrorAction {
  /// ไม่มีอะไรให้กด แค่รับรู้ (ธงปิดฟีเจอร์ / สถานะของบอท)
  none,

  /// ลองใหม่ได้เลย (เน็ตหรือเซิร์ฟเวอร์สะดุดชั่วคราว)
  retry,

  /// เชื่อมกระเป๋าก่อน
  connectWallet,

  /// เซ็นข้อความยืนยันกระเป๋าใหม่ (แคชฝั่งเซิร์ฟเวอร์อายุ 4 ชั่วโมง)
  verifyWallet,

  /// ไปยืนยันตัวตนที่หน้าเว็บ (แอพยังไม่มีหน้ายื่น KYC)
  openKyc,

  /// ไปหน้าเติมเครดิต — ใช้กับ INSUFFICIENT_CREDITS เท่านั้น
  /// (SALES_CLOSED ห้ามพาไปเติมเงิน เพราะเติมไปก็ยังเช่าไม่ได้)
  topUpCredits,

  /// โหลดแคตตาล็อก/สถานะใหม่ เพราะข้อมูลในเครื่องเก่าไปแล้ว
  reload,

  /// พาไปดูแพลน (อัปเกรด/ต่ออายุ)
  upgradePlan,

  /// ไปกด "หยุด" บอทตัวเก่าก่อน — การ "พัก" ไม่ปลดโควตา
  stopAnotherBot,

  /// เลือกคู่เทรดอื่น
  choosePair,

  /// รอครบเวลาที่เซิร์ฟเวอร์บอกแล้วค่อยลองใหม่
  waitCooldown,
}

/// จับคู่รหัสข้อผิดพลาด → ทางออก + ป้ายปุ่ม
class AiBotRecovery {
  AiBotRecovery._();

  static AiBotErrorAction actionFor(ApiErr err) {
    switch (err.code) {
      case AiBotErrorCodes.noWallet:
      case AiBotErrorCodes.invalidWallet:
        return AiBotErrorAction.connectWallet;

      case AiBotErrorCodes.walletNotVerified:
      case AiBotErrorCodes.walletIpMismatch:
        return AiBotErrorAction.verifyWallet;

      case AiBotErrorCodes.kycRequired:
        return AiBotErrorAction.openKyc;

      case AiBotErrorCodes.insufficientCredits:
        return AiBotErrorAction.topUpCredits;

      case AiBotErrorCodes.planNotFound:
      case AiBotErrorCodes.invalidPack:
      case AiBotErrorCodes.botNotFound:
        return AiBotErrorAction.reload;

      case AiBotErrorCodes.strategyLocked:
        return AiBotErrorAction.upgradePlan;

      case AiBotErrorCodes.noSubscription:
        // 422 = แพลนหมดอายุ (ต่ออายุได้) · 403 = ระบบยังไม่ผูกแพลนให้เลย
        return err.status == 422
            ? AiBotErrorAction.upgradePlan
            : AiBotErrorAction.reload;

      case AiBotErrorCodes.botLimitReached:
        return AiBotErrorAction.stopAnotherBot;

      case AiBotErrorCodes.pairNoCandles:
        return AiBotErrorAction.choosePair;

      case AiBotErrorCodes.rateLimited:
        return AiBotErrorAction.waitCooldown;

      case AiBotErrorCodes.network:
      case AiBotErrorCodes.timeout:
      case AiBotErrorCodes.cancelled:
      case AiBotErrorCodes.badPayload:
      case AiBotErrorCodes.subscribeInProgress:
        return AiBotErrorAction.retry;

      case AiBotErrorCodes.salesClosed:
      case AiBotErrorCodes.topupUnavailable:
      case AiBotErrorCodes.liveDisabled:
      case AiBotErrorCodes.resetLimit:
      case AiBotErrorCodes.botBanned:
      case AiBotErrorCodes.botNotRunning:
      case AiBotErrorCodes.cloudBot:
      case AiBotErrorCodes.validation:
        return AiBotErrorAction.none;
    }

    // 5xx และรหัสที่ยังไม่รู้จัก — อย่างน้อยต้องมีปุ่มลองใหม่ให้กด
    return AiBotErrorAction.retry;
  }

  /// ป้ายบนปุ่มทางออก — null เมื่อไม่มีอะไรให้กด
  static String? label(AiBotErrorAction action, bool isThai) {
    switch (action) {
      case AiBotErrorAction.none:
        return null;
      case AiBotErrorAction.retry:
        return isThai ? 'ลองใหม่' : 'Try again';
      case AiBotErrorAction.connectWallet:
        return isThai ? 'เชื่อมกระเป๋า' : 'Connect wallet';
      case AiBotErrorAction.verifyWallet:
        return isThai ? 'เซ็นยืนยันกระเป๋า' : 'Sign to verify';
      case AiBotErrorAction.openKyc:
        return isThai ? 'ไปยืนยันตัวตน' : 'Verify identity';
      case AiBotErrorAction.topUpCredits:
        return isThai ? 'เติมเครดิต' : 'Top up credits';
      case AiBotErrorAction.reload:
        return isThai ? 'โหลดใหม่' : 'Reload';
      case AiBotErrorAction.upgradePlan:
        return isThai ? 'ดูแพลน' : 'See plans';
      case AiBotErrorAction.stopAnotherBot:
        return isThai ? 'ไปจัดการบอท' : 'Manage bots';
      case AiBotErrorAction.choosePair:
        return isThai ? 'เลือกคู่อื่น' : 'Pick another pair';
      case AiBotErrorAction.waitCooldown:
        return isThai ? 'รอสักครู่' : 'Wait a moment';
    }
  }
}

/// รูปแบบที่อยู่กระเป๋าที่เซิร์ฟเวอร์ยอมรับ
final RegExp _walletPattern = RegExp(r'^0x[a-fA-F0-9]{40}$');

/// รูปแบบคู่เทรดที่เซิร์ฟเวอร์ยอมรับ — ต้องมี `/` เช่น BTC/USDT
final RegExp _pairPattern = RegExp(r'^[A-Za-z0-9]{2,15}/[A-Za-z0-9]{2,15}$');

class AiBotApi {
  static final AiBotApi _instance = AiBotApi._();
  factory AiBotApi() => _instance;

  late final Dio _dio;

  /// คิวคำขอตัวเดียว — ยิงขนานกันคือวิธีที่เร็วที่สุดที่จะโดน 429
  Future<void> _queue = Future<void>.value();

  /// เวลาที่ห้ามยิงจนกว่าจะถึง (ตั้งเมื่อโดน 429)
  DateTime? _cooldownUntil;

  AiBotApi._() {
    _dio = Dio(BaseOptions(
      baseUrl: ApiConstants.baseUrl,
      connectTimeout: ApiConstants.timeout,
      receiveTimeout: ApiConstants.timeout,
      sendTimeout: ApiConstants.timeout,
      headers: const {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    ));
  }

  /// เหลือเวลาที่ต้องรออีกเท่าไหร่ก่อนยิงได้ (Duration.zero = ยิงได้เลย)
  Duration get cooldownRemaining {
    final until = _cooldownUntil;
    if (until == null) return Duration.zero;
    final left = until.difference(DateTime.now());
    return left.isNegative ? Duration.zero : left;
  }

  bool get isCoolingDown => cooldownRemaining > Duration.zero;

  /// ล้างสถานะถอย (ใช้เมื่อผู้ใช้กดลองใหม่เองหลังรอครบแล้ว)
  void clearCooldown() => _cooldownUntil = null;

  // ══════════════════════════════════════════════════════════════
  //  Public endpoints — ต้องเรียกได้ก่อนเชื่อมกระเป๋า
  // ══════════════════════════════════════════════════════════════

  /// แคตตาล็อก: แพลน · กลยุทธ์ · แพ็กเครดิต · เพดาน · ธงเปิด/ปิดฟีเจอร์
  /// แคชได้ยาว (มาจาก config + ตาราง plans) เรียกครั้งเดียวต่อเซสชันพอ
  Future<ApiResult<AiBotCatalog>> fetchCatalog() async {
    final res = await _raw('GET', AiBotEndpoints.catalog);
    return _asMap(res, AiBotCatalog.fromJson);
  }

  /// มุมมองตลาดของ AI — ตอบ 3 รูปร่าง (ปิดระบบ / ยังไม่มีมุมมอง / มีมุมมอง)
  Future<ApiResult<AiMarketView>> fetchMarketView() async {
    final res = await _raw('GET', AiBotEndpoints.marketView);
    return _asMap(res, AiMarketView.fromJson);
  }

  /// ด่านความเสี่ยงของคู่เทรด — [pair] ต้องมี `/` เช่น "BTC/USDT"
  Future<ApiResult<AiMarketRisk>> fetchRisk(String pair) async {
    final normalized = pair.trim().toUpperCase();
    if (!_pairPattern.hasMatch(normalized)) {
      return ApiErr<AiMarketRisk>(
        AiBotErrorCodes.validation,
        'รูปแบบคู่เทรดไม่ถูกต้อง',
        fieldErrors: const {
          'pair': ['ต้องอยู่ในรูป BTC/USDT'],
        },
      );
    }
    final res = await _raw(
      'GET',
      AiBotEndpoints.risk,
      query: {'pair': normalized},
    );
    return _asMap(res, AiMarketRisk.fromJson);
  }

  // ══════════════════════════════════════════════════════════════
  //  Protected endpoints — ต้องยืนยันกระเป๋า
  // ══════════════════════════════════════════════════════════════

  /// สถานะรวมของกระเป๋า (เครดิต · แพลน · โควตา · บอททุกตัว)
  ///
  /// ⚠️ endpoint นี้เขียนฐานข้อมูล (สร้าง subscription แพลนฟรีให้อัตโนมัติถ้ายัง
  /// ไม่มี) เรียกครั้งแรกจึงช้ากว่าปกติเล็กน้อย และไม่ควรยิงพร้อมกันหลายเส้น
  Future<ApiResult<AiBotStatus>> fetchStatus(String wallet) async {
    final guard = _wallet<AiBotStatus>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.status,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotStatus.fromJson);
  }

  /// ยอดเครดิต + เดินบัญชี 50 รายการล่าสุด (ไม่มี pagination)
  Future<ApiResult<AiBotCredits>> fetchCredits(String wallet) async {
    final guard = _wallet<AiBotCredits>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.credits,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotCredits.fromJson);
  }

  /// รับเครดิตต้อนรับ
  ///
  /// ⚠️ Idempotent — กดซ้ำไม่ error และไม่เพิ่มเงิน คืนยอดเดิม
  /// ค่าที่ได้คือ "ยอดคงเหลือใหม่ทั้งหมด" ไม่ใช่ "โบนัสที่เพิ่งได้"
  /// → ต้องเทียบยอดก่อน/หลังเองก่อนขึ้นข้อความว่ารับสำเร็จ ไม่งั้นคนที่เคยรับ
  ///   ไปแล้วจะเห็น "รับ 100 เครดิตแล้ว!" ทั้งที่ยอดไม่ขยับ
  Future<ApiResult<double>> claimWelcome(String wallet) async {
    final guard = _wallet<double>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.welcome,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, (json) => _asDouble(json['credits']));
  }

  /// สร้างคำขอเติมเครดิต (ยังไม่มีเครดิตเข้าจนกว่าทีมงานยืนยันการชำระเงิน)
  ///
  /// ต้องอ่านธง `catalog.features.creditTopup` แล้วปิดปุ่มเองก่อน — ถ้ายังยิงมา
  /// จะได้ `TOPUP_UNAVAILABLE`
  Future<ApiResult<AiBotTopupRequest>> requestTopup({
    required String wallet,
    required String packCode,
  }) async {
    final guard = _wallet<AiBotTopupRequest>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.topup,
      body: {
        'wallet_address': _normalize(wallet),
        'pack': packCode,
      },
    );
    return _asMap(res, AiBotTopupRequest.fromJson);
  }

  /// เช่า/ต่ออายุแพลน — คืน payload ของ `/status` เต็มก้อน
  ///
  /// [days] ต้องเป็นค่าจาก `catalog.rentalDays` เท่านั้น (เซิร์ฟเวอร์ clamp
  /// ค่าที่ไม่อยู่ใน [1,7,30,90] ให้เหลือ 1 เงียบๆ) → UI ต้องเป็นปุ่มให้เลือก
  /// ห้ามมีช่องพิมพ์เลขวัน
  ///
  /// เปลี่ยนแพลนไม่ใช่การต่ออายุ — เซิร์ฟเวอร์คืนเงินวันที่เหลือ เริ่มรอบใหม่
  /// และเขียนทับกรอบความเสี่ยงของบอททุกตัว → ต้องใช้สถานะที่คืนมาเป็นความจริง
  /// ห้ามใช้ค่าเก่าที่ค้างในหน่วยความจำ
  Future<ApiResult<AiBotStatus>> subscribe({
    required String wallet,
    required String planCode,
    required int days,
  }) async {
    final guard = _wallet<AiBotStatus>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.subscribe,
      body: {
        'wallet_address': _normalize(wallet),
        'plan_code': planCode,
        'days': days,
      },
    );
    return _asMap(res, AiBotStatus.fromJson);
  }

  /// ยกเลิกการเช่า — หยุดบอททุกตัวและคืนเครดิตตามเศษวันจริง
  ///
  /// ⚠️ เซิร์ฟเวอร์ไม่ถามซ้ำและไม่มี error เชิงธุรกิจเลย หน้าจอต้องมีกล่องยืนยัน
  /// ที่บอกว่าบอทกี่ตัวจะหยุด และจะได้เครดิตคืนเท่าไหร่
  Future<ApiResult<AiBotStatus>> cancelSubscription(String wallet) async {
    final guard = _wallet<AiBotStatus>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.cancel,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotStatus.fromJson);
  }

  /// รายการบอททั้งหมด
  ///
  /// ⚠️ endpoint นี้วาง payload ไว้ที่ `data` ตรงๆ (ต่างจาก `/status` ที่ห่อไว้
  /// ใน `data.bots`) — จัดการให้แล้วในนี้
  Future<ApiResult<List<AiBot>>> fetchBots(String wallet) async {
    final guard = _wallet<List<AiBot>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.bots,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asList(res, AiBot.fromJson);
  }

  /// สร้างบอท (เซิร์ฟเวอร์ตอบ HTTP 201) — บอทเริ่มที่สถานะ "paused" เสมอ
  /// ต้องยิง [setBotState] ด้วย `start` ต่ออีกครั้งถึงจะเดิน
  ///
  /// payload ประกอบจาก `AiBotDraft.toRequestJson()` จุดเดียว — ที่นั่นคือที่ที่
  /// กับดัก `max_position_usd_requested` ถูกปิดไว้ (คีย์ requested มีลำดับสูงกว่า
  /// ฝั่งเซิร์ฟเวอร์ ถ้าส่งค่าเก่าติดไป ค่าที่ผู้ใช้เพิ่งพิมพ์จะถูกทิ้งเงียบๆ
  /// แล้วเซิร์ฟเวอร์ตอบ 200 เหมือนสำเร็จทุกอย่าง)
  ///
  /// ⚠️ เซิร์ฟเวอร์ clamp ค่าที่ส่งไปเงียบๆ (target_bps, range_pct,
  /// max_position_usd ฯลฯ) → ต้องใช้บอทที่คืนมาเป็นความจริง ห้ามใช้ค่าในฟอร์ม
  Future<ApiResult<AiBot>> createBot({
    required String wallet,
    required AiBotDraft draft,
  }) async {
    final guard = _wallet<AiBot>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.bots,
      body: {
        'wallet_address': _normalize(wallet),
        ...draft.toRequestJson(),
      },
    );
    return _asMap(res, AiBot.fromJson);
  }

  /// แก้บอท — เป็น PUT เต็มก้อน ไม่ใช่ PATCH (ทุกฟิลด์ต้องส่งครบทุกครั้ง)
  ///
  /// เซิร์ฟเวอร์ไม่หยุดบอทให้ก่อนแก้ บอทอาจเดินรอบถัดไปด้วยค่าใหม่ทันที
  Future<ApiResult<AiBot>> updateBot({
    required String wallet,
    required int botId,
    required AiBotDraft draft,
  }) async {
    final guard = _wallet<AiBot>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'PUT',
      AiBotEndpoints.bot(botId),
      body: {
        'wallet_address': _normalize(wallet),
        ...draft.toRequestJson(),
      },
    );
    return _asMap(res, AiBot.fromJson);
  }

  /// เริ่ม / พัก / หยุดบอท
  ///
  /// ด่านทั้งหมดอยู่ที่ `start` เท่านั้น — `pause`/`stop` ผ่านเสมอแม้บอทถูกระงับ
  /// (ปุ่มหยุดจึงต้องกดได้ตลอด) และมีแต่ `stop` ที่ปลดโควตา
  Future<ApiResult<AiBot>> setBotState({
    required String wallet,
    required int botId,
    required AiBotStateAction action,
  }) async {
    final guard = _wallet<AiBot>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.botState(botId),
      body: {
        'wallet_address': _normalize(wallet),
        'action': action.wire,
      },
    );
    return _asMap(res, AiBot.fromJson);
  }

  /// สลับโหมดทดลอง ↔ จริง
  ///
  /// ⚠️ ของที่ถืออยู่ไม่ตามไปด้วย (คนละชุดตามโหมด) — ต้องเตือนผู้ใช้เมื่อบอท
  /// ยังถือไม้อยู่ และโหมดจริงตอนนี้ยังปิด (`LIVE_DISABLED`) ให้อ่านธงจาก
  /// catalog แล้วปิดสวิตช์ตั้งแต่แรก อย่าปล่อยให้กดแล้วเด้ง error
  Future<ApiResult<AiBot>> setBotMode({
    required String wallet,
    required int botId,
    required AiBotMode mode,
  }) async {
    final guard = _wallet<AiBot>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.botMode(botId),
      body: {
        'wallet_address': _normalize(wallet),
        'mode': mode.wire,
      },
    );
    return _asMap(res, AiBot.fromJson);
  }

  /// สั่งบอทเดินหนึ่งรอบ — **แพลนที่บอทเดินในแอพเท่านั้น**
  ///
  /// เรียกเฉพาะเมื่อ `subscription.execution == "browser"` และบอท running
  /// ระยะขั้นต่ำฝั่งเซิร์ฟเวอร์ 30 วินาทีต่อบอท ยิงถี่กว่านั้นได้ `skipped: true`
  /// ซึ่ง **ไม่ใช่ความล้มเหลว** ห้ามนับเป็น error
  Future<ApiResult<AiBotTickResult>> tickBot({
    required String wallet,
    required int botId,
  }) async {
    final guard = _wallet<AiBotTickResult>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.botTick(botId),
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotTickResult.fromJson);
  }

  /// ลบบอท — ส่งกระเป๋าทาง query string (พร็อกซี/CDN บางตัวตัด body ของ DELETE)
  ///
  /// ⚠️ ไม่มีด่านอะไรนอกจากเป็นเจ้าของ ลบบอทที่กำลังเดินและถือของอยู่ได้ทันที
  /// หน้าจอต้องมีกล่องยืนยัน + เตือนว่าไม้ที่ถืออยู่จะถูกทิ้ง
  Future<ApiResult<int>> deleteBot({
    required String wallet,
    required int botId,
  }) async {
    final guard = _wallet<int>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'DELETE',
      AiBotEndpoints.bot(botId),
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, (json) => _asDouble(json['deleted']).toInt());
  }

  /// พอร์ตทดลอง (ยอด · ของที่ถือ · ไม้ล่าสุด 60 · พอร์ตแยกกลยุทธ์ · สรุป)
  Future<ApiResult<AiBotDemo>> fetchDemo(String wallet) async {
    final guard = _wallet<AiBotDemo>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.demo,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotDemo.fromJson);
  }

  /// ล้างพอร์ตทดลอง — คืน payload ของ `/demo` เต็มก้อน
  ///
  /// ⚠️ ทำลายข้อมูลถาวร: ลบทั้งไม้และของที่ถืออยู่ของทุกบอทในกระเป๋า และทำให้
  /// `/analytics` ว่างตามไปด้วย (อ่านตารางเดียวกัน) ต้องบอกผู้ใช้ให้ชัดก่อนกด
  Future<ApiResult<AiBotDemo>> resetDemo(String wallet) async {
    final guard = _wallet<AiBotDemo>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.demoReset,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotDemo.fromJson);
  }

  /// ไม้ของบอททุกโหมดในคู่ที่ระบุ — เรียงเก่า→ใหม่ พร้อมปักบนกราฟ
  ///
  /// ต่างจาก `/demo` ที่ให้เฉพาะไม้กระดาษ 60 ไม้ล่าสุด — พอมีโหมดจริง กราฟต้องเห็นครบ
  Future<ApiResult<List<DemoTrade>>> fetchTrades({
    required String wallet,
    String? pair,
    int limit = 300,
  }) async {
    final guard = _wallet<List<DemoTrade>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.trades,
      query: {
        'wallet_address': _normalize(wallet),
        if (pair != null && pair.isNotEmpty) 'pair': pair,
        'limit': '$limit',
      },
    );
    return _asList(res, DemoTrade.fromJson);
  }

  // ── กระเป๋าบอท ────────────────────────────────────────────────

  /// สถานะกระเป๋าบอท: เปิดฟีเจอร์ไหม · กระเป๋า (null = ยังไม่สร้าง) · รายการโอน
  Future<ApiResult<BotWalletState>> fetchBotWallet(String wallet) async {
    final guard = _wallet<BotWalletState>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.wallet,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, BotWalletState.fromJson);
  }

  /// สร้างกระเป๋าบอท (หนึ่งใบต่อกระเป๋าเจ้าของ — เรียกซ้ำได้ใบเดิม)
  /// คืน payload บางส่วน { wallet, transfers } — ผู้เรียก merge เข้าของเดิม
  Future<ApiResult<Map<String, dynamic>>> createBotWallet(String wallet) async {
    final guard = _wallet<Map<String, dynamic>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.wallet,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, (json) => json);
  }

  /// อ่านยอดจากเชนใหม่ตอนนี้
  Future<ApiResult<Map<String, dynamic>>> refreshBotWallet(String wallet) async {
    final guard = _wallet<Map<String, dynamic>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.walletRefresh,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, (json) => json);
  }

  /// ขอถอนกลับกระเป๋าของตัวเอง — ไม่มีช่องปลายทางโดยตั้งใจ (เซิร์ฟเวอร์ก็ไม่รับ)
  Future<ApiResult<Map<String, dynamic>>> withdrawBotWallet({
    required String wallet,
    required String asset,
    required double amount,
  }) async {
    final guard = _wallet<Map<String, dynamic>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.walletWithdraw,
      body: {
        'wallet_address': _normalize(wallet),
        'asset': asset,
        'amount': amount,
      },
    );
    return _asMap(res, (json) => json);
  }

  /// ยกเลิกรายการถอนที่ยังไม่ถูกส่ง
  Future<ApiResult<Map<String, dynamic>>> cancelBotWalletWithdraw({
    required String wallet,
    required int transferId,
  }) async {
    final guard = _wallet<Map<String, dynamic>>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.walletCancel(transferId),
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, (json) => json);
  }

  /// สถิติย้อนหลัง (รวม · รายกลยุทธ์ · รายคู่ · รายระดับความเสี่ยงตอนเข้าไม้)
  ///
  /// ไม่มี pagination ฝั่งเซิร์ฟเวอร์ กระเป๋าที่มีไม้เยอะจะช้า — อย่ายิงถี่
  Future<ApiResult<AiBotAnalytics>> fetchAnalytics({
    required String wallet,
    AiBotMode mode = AiBotMode.demo,
  }) async {
    final guard = _wallet<AiBotAnalytics>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'GET',
      AiBotEndpoints.analytics,
      query: {
        'wallet_address': _normalize(wallet),
        'mode': mode.wire,
      },
    );
    return _asMap(res, AiBotAnalytics.fromJson);
  }

  /// ประวัติการตัดสินใจของบอท — หัวใจของหน้ามอนิเตอร์
  ///
  /// ตาราง `ai_bot_decisions` เก็บ "ทุกครั้งที่บอทคิด" รวมรอบที่ตัดสินใจ
  /// ไม่ทำอะไร ซึ่งเกิดบ่อยกว่าการเข้าไม้หลายสิบเท่าและเป็นตัวบอกว่าบอท
  /// ทำงานถูกหรือเปล่า — เดิมเจ้าของบอทเห็นได้แค่เหตุผลรอบล่าสุดรอบเดียว
  ///
  /// เลื่อนหน้าด้วย cursor: ส่ง [beforeId] เป็น `nextCursor` ที่ได้จากหน้าก่อน
  /// (ห้ามใช้เลขหน้า — ตารางนี้โตวันละหลายพันแถว ข้อมูลจะเลื่อนระหว่างหน้า)
  Future<ApiResult<AiBotDecisionPage>> fetchDecisions({
    required String wallet,
    int? botId,
    AiBotMode? mode,
    bool actedOnly = false,
    int limit = 40,
    int? beforeId,
  }) async {
    final guard = _wallet<AiBotDecisionPage>(wallet);
    if (guard != null) return guard;

    final query = <String, dynamic>{
      'wallet_address': _normalize(wallet),
      'limit': limit.clamp(1, 100),
    };
    if (botId != null) query['bot_id'] = botId;
    if (mode != null) query['mode'] = mode.wire;
    if (actedOnly) query['acted_only'] = 1;
    if (beforeId != null) query['before_id'] = beforeId;

    final res = await _raw('GET', AiBotEndpoints.decisions, query: query);
    return _asMap(res, AiBotDecisionPage.fromJson);
  }

  /// ขอคำแนะนำจากที่ปรึกษา AI
  ///
  /// ⚠️ ความล้มเหลวเชิงธุรกิจมาเป็น HTTP 200 พร้อม `ok: false` + `reason`
  /// (prod ยังไม่ได้ตั้งคีย์ผู้ให้บริการ จึงได้ `ok:false` เป็นปกติ ไม่ใช่ error)
  /// เซิร์ฟเวอร์แคชคำตอบ 15 นาที แต่ยังกินโควตา → ต้อง disable ปุ่มระหว่างรอ
  Future<ApiResult<AiBotAdvice>> fetchAdvice(String wallet) async {
    final guard = _wallet<AiBotAdvice>(wallet);
    if (guard != null) return guard;
    final res = await _raw(
      'POST',
      AiBotEndpoints.advice,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asMap(res, AiBotAdvice.fromJson);
  }

  // ══════════════════════════════════════════════════════════════
  //  ชั้นส่งคำขอ
  // ══════════════════════════════════════════════════════════════

  /// เซิร์ฟเวอร์ normalize ที่อยู่เป็นตัวเล็กอยู่แล้ว แต่ทำฝั่งแอพด้วยเพื่อให้
  /// การเทียบสตริง/คีย์แคชในแอพตรงกันเสมอ
  String _normalize(String wallet) => wallet.trim().toLowerCase();

  /// ตรวจที่อยู่ก่อนยิง — คืน `ApiErr` เมื่อไม่ผ่าน, คืน null เมื่อผ่าน
  ApiErr<T>? _wallet<T>(String? wallet) {
    final w = wallet?.trim() ?? '';
    if (w.isEmpty) {
      return ApiErr<T>(AiBotErrorCodes.noWallet, 'กรุณาเชื่อมกระเป๋าก่อน');
    }
    if (!_walletPattern.hasMatch(w)) {
      return ApiErr<T>(
        AiBotErrorCodes.invalidWallet,
        'รูปแบบที่อยู่กระเป๋าไม่ถูกต้อง',
      );
    }
    return null;
  }

  /// ยิงคำขอผ่านคิวตัวเดียว
  Future<ApiResult<Object?>> _raw(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
  }) {
    return _enqueue(() => _execute(method, path, query: query, body: body));
  }

  Future<T> _enqueue<T>(Future<T> Function() task) {
    final completer = Completer<T>();
    _queue = _queue.then((_) async {
      try {
        completer.complete(await task());
      } catch (e, st) {
        // จับไว้เองเพื่อไม่ให้คิวขาด — งานถัดไปต้องเดินต่อได้เสมอ
        completer.completeError(e, st);
      }
    });
    return completer.future;
  }

  Future<ApiResult<Object?>> _execute(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
  }) async {
    // ถอยเองระหว่างที่เซิร์ฟเวอร์ยังสั่งให้รอ — ยิงไปก็เสียโควตาเปล่า และทำให้
    // ตัวนับของ IP นี้ (ซึ่งแชร์กับผู้ใช้คนอื่น) ยิ่งเต็ม
    final wait = cooldownRemaining;
    if (wait > Duration.zero) {
      final secs = wait.inSeconds + (wait.inMilliseconds % 1000 > 0 ? 1 : 0);
      return ApiErr<Object?>(
        AiBotErrorCodes.rateLimited,
        'ส่งคำขอถี่เกินไป กรุณารออีก $secs วินาที',
        status: 429,
        retryAfterSeconds: secs,
      );
    }

    try {
      final res = await _dio.request<dynamic>(
        path,
        queryParameters: query,
        data: body,
        options: Options(method: method),
      );

      final payload = res.data;
      if (payload is! Map) {
        return ApiErr<Object?>(
          AiBotErrorCodes.badPayload,
          'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
          status: res.statusCode,
        );
      }

      final map = payload.map((k, v) => MapEntry(k.toString(), v));
      if (map['success'] != true) {
        return _errorFrom(map, res.statusCode, res.headers);
      }

      // สำเร็จแล้วถือว่าโควตากลับมาปกติ
      _cooldownUntil = null;
      return ApiOk<Object?>(map['data']);
    } on DioException catch (e) {
      return _fromDioException(e, method, path);
    } catch (e) {
      debugPrint('[AiBotApi] $method $path: ${e.runtimeType}');
      return ApiErr<Object?>(
        AiBotErrorCodes.unknown,
        'เกิดข้อผิดพลาดที่ไม่คาดคิด',
      );
    }
  }

  ApiResult<Object?> _fromDioException(
    DioException e,
    String method,
    String path,
  ) {
    final status = e.response?.statusCode;
    // log เฉพาะสถานะ ห้ามพิมพ์ payload (มีที่อยู่กระเป๋าอยู่ในนั้น)
    debugPrint('[AiBotApi] $method $path -> ${status ?? e.type.name}');

    if (status == 429) return _throttled(e.response?.headers);

    final data = e.response?.data;
    if (data is Map) {
      return _errorFrom(
        data.map((k, v) => MapEntry(k.toString(), v)),
        status,
        e.response?.headers,
      );
    }

    if (status == null) {
      if (e.type == DioExceptionType.cancel) {
        return ApiErr<Object?>(AiBotErrorCodes.cancelled, 'ยกเลิกคำขอแล้ว');
      }
      final timedOut = e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.sendTimeout ||
          e.type == DioExceptionType.receiveTimeout;
      return ApiErr<Object?>(
        timedOut ? AiBotErrorCodes.timeout : AiBotErrorCodes.network,
        timedOut ? 'เซิร์ฟเวอร์ตอบช้าเกินไป' : 'ต่ออินเทอร์เน็ตไม่ได้',
      );
    }

    return ApiErr<Object?>(
      'HTTP_$status',
      'เซิร์ฟเวอร์ตอบผิดพลาด',
      status: status,
    );
  }

  /// อ่านซองความล้มเหลว 4 แบบที่เซิร์ฟเวอร์ส่งได้จริง
  ApiResult<Object?> _errorFrom(
    Map<String, dynamic> map,
    int? status,
    Headers? headers,
  ) {
    // แบบที่ 1 — ซองปกติของ controller: {success:false, error:{code,message}}
    final err = map['error'];
    if (err is Map) {
      final e = err.map((k, v) => MapEntry(k.toString(), v));
      return ApiErr<Object?>(
        _str(e['code']) ?? AiBotErrorCodes.unknown,
        _str(e['message']) ?? 'ทำรายการไม่สำเร็จ',
        status: status,
        // สองตัวนี้มีเฉพาะ KYC_REQUIRED
        kycFeature: _str(e['feature']),
        kycLevel: _str(e['level']),
      );
    }

    // แบบที่ 2 — validator ของ Laravel: {message, errors:{field:[...]}}
    // ซองนี้ไม่มี error.code จึงต้องตั้งรหัสเอง
    final errors = map['errors'];
    if (errors is Map) {
      final fields = <String, List<String>>{};
      errors.forEach((key, value) {
        final list = <String>[];
        if (value is List) {
          for (final item in value) {
            final s = _str(item);
            if (s != null) list.add(s);
          }
        } else {
          final s = _str(value);
          if (s != null) list.add(s);
        }
        if (list.isNotEmpty) fields[key.toString()] = list;
      });
      return ApiErr<Object?>(
        AiBotErrorCodes.validation,
        _str(map['message']) ?? 'ข้อมูลที่กรอกไม่ถูกต้อง',
        status: status ?? 422,
        fieldErrors: fields,
      );
    }

    // แบบที่ 3 — throttle: {message: "Too Many Attempts."}
    if (status == 429) return _throttled(headers);

    // แบบที่ 4 — 404/500 ดิบของ Laravel: {message: "..."}
    return ApiErr<Object?>(
      status == null ? AiBotErrorCodes.unknown : 'HTTP_$status',
      _str(map['message']) ?? 'ทำรายการไม่สำเร็จ',
      status: status,
    );
  }

  ApiErr<Object?> _throttled(Headers? headers) {
    final retry = _retryAfter(headers);
    _cooldownUntil = DateTime.now().add(Duration(seconds: retry));
    return ApiErr<Object?>(
      AiBotErrorCodes.rateLimited,
      'ส่งคำขอถี่เกินไป กรุณารออีก $retry วินาที',
      status: 429,
      retryAfterSeconds: retry,
    );
  }

  int _retryAfter(Headers? headers) {
    final raw = headers?.value('retry-after');
    final secs = int.tryParse(raw?.trim() ?? '');
    if (secs == null || secs <= 0) return 30;
    // กันค่าประหลาดจากพร็อกซี — รอเกิน 5 นาทีไม่สมเหตุสมผลกับหน้าจอ
    return secs > 300 ? 300 : secs;
  }

  // ── แปลง payload เป็นโมเดล (พังก็ยังต้องมีเหตุผล) ──

  ApiResult<T> _asMap<T>(
    ApiResult<Object?> res,
    T Function(Map<String, dynamic> json) build,
  ) {
    switch (res) {
      case ApiErr<Object?>():
        return _reshape<T>(res);
      case ApiOk<Object?>(:final data):
        if (data is! Map) {
          return ApiErr<T>(
            AiBotErrorCodes.badPayload,
            'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
          );
        }
        try {
          return ApiOk<T>(build(data.map((k, v) => MapEntry(k.toString(), v))));
        } catch (e) {
          debugPrint('[AiBotApi] parse $T: ${e.runtimeType}');
          return ApiErr<T>(
            AiBotErrorCodes.badPayload,
            'อ่านข้อมูลจากเซิร์ฟเวอร์ไม่สำเร็จ',
          );
        }
    }
  }

  ApiResult<List<T>> _asList<T>(
    ApiResult<Object?> res,
    T Function(Map<String, dynamic> json) build,
  ) {
    switch (res) {
      case ApiErr<Object?>():
        return _reshape<List<T>>(res);
      case ApiOk<Object?>(:final data):
        if (data is! List) {
          return ApiErr<List<T>>(
            AiBotErrorCodes.badPayload,
            'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
          );
        }
        try {
          final out = <T>[];
          for (final item in data) {
            if (item is Map) {
              out.add(build(item.map((k, v) => MapEntry(k.toString(), v))));
            }
          }
          return ApiOk<List<T>>(out);
        } catch (e) {
          debugPrint('[AiBotApi] parse List<$T>: ${e.runtimeType}');
          return ApiErr<List<T>>(
            AiBotErrorCodes.badPayload,
            'อ่านข้อมูลจากเซิร์ฟเวอร์ไม่สำเร็จ',
          );
        }
    }
  }

  /// ส่งต่อ error ข้ามชนิดผลลัพธ์โดยไม่ทำเหตุผลหายแม้แต่ฟิลด์เดียว
  ApiErr<T> _reshape<T>(ApiErr<Object?> err) => ApiErr<T>(
        err.code,
        err.message,
        status: err.status,
        fieldErrors: err.fieldErrors,
        retryAfterSeconds: err.retryAfterSeconds,
        kycFeature: err.kycFeature,
        kycLevel: err.kycLevel,
      );

  static String? _str(dynamic value) {
    if (value == null) return null;
    final s = value.toString().trim();
    return s.isEmpty ? null : s;
  }

  /// decimal ของ PHP อาจมาเป็น String — ต้องรับได้ทั้งสองแบบ
  static double _asDouble(dynamic value) {
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value.trim()) ?? 0;
    return 0;
  }
}

/// Developed by Xman Studio
